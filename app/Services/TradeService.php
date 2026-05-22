<?php

namespace App\Services;

use App\Models\EscrowItem;
use App\Models\InventoryItem;
use App\Models\Trade;
use App\Models\TradeItem;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TradeService
{
    public const MAX_PENDING_TRADES_PER_USER = 10;

    public function __construct(
        private readonly EscrowService $escrowService,
    ) {
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    public function propose(User $initiator, array $validated): Trade
    {
        return DB::transaction(function () use ($initiator, $validated): Trade {
            $this->validatePendingTradeLimit($initiator, (int) $validated['recipient_id']);

            $trade = Trade::query()->create([
                'initiator_id' => $initiator->id,
                'recipient_id' => $validated['recipient_id'],
                'guild_id' => $validated['guild_id'],
                'status' => Trade::STATUS_PENDING,
                'expires_at' => now()->addHours(24),
            ]);

            $this->createTradeItems($trade, $validated['offered_items'], $initiator->id);
            $this->createTradeItems($trade, $validated['requested_items'], (int) $validated['recipient_id']);

            $this->escrowService->lockItemsForTrade($trade, $validated['offered_items']);

            return $trade->load([
                'tradeItems.inventoryItem.item',
                'escrowItems.inventoryItem.item',
            ]);
        });
    }

    private function validatePendingTradeLimit(User $initiator, int $recipientId): void
    {
        foreach ([(int) $initiator->id, $recipientId] as $userId) {
            $pendingCount = Trade::query()
                ->where('status', Trade::STATUS_PENDING)
                ->where(function ($query) use ($userId): void {
                    $query->where('initiator_id', $userId)
                        ->orWhere('recipient_id', $userId);
                })
                ->lockForUpdate()
                ->count();

            if ($pendingCount >= self::MAX_PENDING_TRADES_PER_USER) {
                throw ValidationException::withMessages([
                    'pending_trades' => 'A user cannot have more than '.self::MAX_PENDING_TRADES_PER_USER.' pending trades.',
                ]);
            }
        }
    }

    public function accept(Trade $trade, User $user): Trade
    {
        return DB::transaction(function () use ($trade, $user): Trade {
            $lockedTrade = Trade::query()
                ->whereKey($trade->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ((int) $lockedTrade->recipient_id !== (int) $user->id) {
                throw ValidationException::withMessages([
                    'trade' => 'Only the recipient can accept this trade.',
                ]);
            }

            if (! $lockedTrade->isPending()) {
                throw ValidationException::withMessages([
                    'trade' => 'Trade is no longer pending.',
                ]);
            }

            $tradeItems = $lockedTrade->tradeItems()
                ->lockForUpdate()
                ->get();
            $escrowItems = $lockedTrade->escrowItems()
                ->lockForUpdate()
                ->get();
            $inventoryItems = $this->lockedInventoryItems($tradeItems, $escrowItems);

            $offeredItems = $tradeItems->where('from_user_id', $lockedTrade->initiator_id);
            $requestedItems = $tradeItems->where('from_user_id', $lockedTrade->recipient_id);
            $offeredQuantities = $this->quantitiesByInventoryItem($offeredItems);
            $requestedQuantities = $this->quantitiesByInventoryItem($requestedItems);
            $escrowQuantities = $this->quantitiesByEscrowInventoryItem($escrowItems);

            $this->validateOfferedItemsForAccept($offeredQuantities, $escrowQuantities, $inventoryItems, $lockedTrade);
            $this->validateRequestedItemsForAccept($requestedQuantities, $inventoryItems, $lockedTrade);

            foreach ($offeredQuantities as $inventoryItemId => $quantity) {
                $this->transferInventoryItem(
                    $inventoryItems->get($inventoryItemId),
                    (int) $lockedTrade->recipient_id,
                    $quantity
                );
            }

            foreach ($requestedQuantities as $inventoryItemId => $quantity) {
                $this->transferInventoryItem(
                    $inventoryItems->get($inventoryItemId),
                    (int) $lockedTrade->initiator_id,
                    $quantity
                );
            }

            $this->escrowService->releaseEscrow($lockedTrade);
            $lockedTrade->forceFill(['status' => Trade::STATUS_COMPLETED])->save();

            return $this->loadTradeRelations($lockedTrade);
        });
    }

    public function reject(Trade $trade, User $user): Trade
    {
        return DB::transaction(function () use ($trade, $user): Trade {
            $lockedTrade = Trade::query()
                ->whereKey($trade->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ((int) $lockedTrade->recipient_id !== (int) $user->id) {
                throw ValidationException::withMessages([
                    'trade' => 'Only the recipient can reject this trade.',
                ]);
            }

            if (! $lockedTrade->isPending()) {
                throw ValidationException::withMessages([
                    'trade' => 'Trade is no longer pending.',
                ]);
            }

            $this->escrowService->releaseEscrow($lockedTrade);

            $lockedTrade->forceFill(['status' => Trade::STATUS_REJECTED])->save();

            return $this->loadTradeRelations($lockedTrade);
        });
    }

    public function cancel(Trade $trade, User $user): Trade
    {
        return DB::transaction(function () use ($trade, $user): Trade {
            $lockedTrade = Trade::query()
                ->whereKey($trade->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ((int) $lockedTrade->initiator_id !== (int) $user->id) {
                throw ValidationException::withMessages([
                    'trade' => 'Only the initiator can cancel this trade.',
                ]);
            }

            if (! $lockedTrade->isPending()) {
                throw ValidationException::withMessages([
                    'trade' => 'Trade is no longer pending.',
                ]);
            }

            $this->escrowService->releaseEscrow($lockedTrade);

            $lockedTrade->forceFill(['status' => Trade::STATUS_CANCELLED])->save();

            return $this->loadTradeRelations($lockedTrade);
        });
    }

    public function expireIfPending(Trade $trade): Trade
    {
        return DB::transaction(function () use ($trade): Trade {
            $lockedTrade = Trade::query()
                ->whereKey($trade->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (! $lockedTrade->isPending()) {
                return $this->loadTradeRelations($lockedTrade);
            }

            $this->escrowService->releaseEscrow($lockedTrade);

            $lockedTrade->forceFill(['status' => Trade::STATUS_EXPIRED])->save();

            return $this->loadTradeRelations($lockedTrade);
        });
    }

    /**
     * @param  array<int, array<string, int>>  $items
     */
    private function createTradeItems(Trade $trade, array $items, int $fromUserId): void
    {
        foreach ($items as $item) {
            TradeItem::query()->create([
                'trade_id' => $trade->id,
                'inventory_item_id' => $item['inventory_item_id'],
                'from_user_id' => $fromUserId,
                'quantity' => $item['quantity'],
            ]);
        }
    }

    private function loadTradeRelations(Trade $trade): Trade
    {
        return $trade->load([
            'tradeItems.inventoryItem.item',
            'escrowItems.inventoryItem.item',
        ]);
    }

    /**
     * @param  Collection<int, TradeItem>  $tradeItems
     * @param  Collection<int, EscrowItem>  $escrowItems
     * @return Collection<int, InventoryItem>
     */
    private function lockedInventoryItems(Collection $tradeItems, Collection $escrowItems): Collection
    {
        $inventoryItemIds = $tradeItems
            ->pluck('inventory_item_id')
            ->merge($escrowItems->pluck('inventory_item_id'))
            ->unique()
            ->values();

        $inventoryItems = InventoryItem::query()
            ->whereIn('id', $inventoryItemIds->all())
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        if ($inventoryItems->count() !== $inventoryItemIds->count()) {
            throw ValidationException::withMessages([
                'inventory_items' => 'One or more selected inventory items no longer exists.',
            ]);
        }

        return $inventoryItems;
    }

    /**
     * @param  Collection<int, TradeItem>  $tradeItems
     * @return Collection<int, int>
     */
    private function quantitiesByInventoryItem(Collection $tradeItems): Collection
    {
        return $tradeItems
            ->groupBy(fn (TradeItem $tradeItem): int => (int) $tradeItem->inventory_item_id)
            ->map(fn (Collection $items): int => $items->sum(
                fn (TradeItem $tradeItem): int => (int) $tradeItem->quantity
            ));
    }

    /**
     * @param  Collection<int, EscrowItem>  $escrowItems
     * @return Collection<int, int>
     */
    private function quantitiesByEscrowInventoryItem(Collection $escrowItems): Collection
    {
        return $escrowItems
            ->groupBy(fn (EscrowItem $escrowItem): int => (int) $escrowItem->inventory_item_id)
            ->map(fn (Collection $items): int => $items->sum(
                fn (EscrowItem $escrowItem): int => (int) $escrowItem->quantity
            ));
    }

    /**
     * @param  Collection<int, int>  $offeredQuantities
     * @param  Collection<int, int>  $escrowQuantities
     * @param  Collection<int, InventoryItem>  $inventoryItems
     */
    private function validateOfferedItemsForAccept(
        Collection $offeredQuantities,
        Collection $escrowQuantities,
        Collection $inventoryItems,
        Trade $trade
    ): void {
        foreach ($offeredQuantities as $inventoryItemId => $quantity) {
            $inventoryItem = $inventoryItems->get($inventoryItemId);

            if ((int) $inventoryItem->user_id !== (int) $trade->initiator_id) {
                throw ValidationException::withMessages([
                    'inventory_items' => 'One or more offered inventory items is no longer owned by the initiator.',
                ]);
            }

            if (! $inventoryItem->is_in_escrow) {
                throw ValidationException::withMessages([
                    'inventory_items' => 'One or more offered inventory items is no longer in escrow.',
                ]);
            }

            if ($escrowQuantities->get($inventoryItemId, 0) < $quantity) {
                throw ValidationException::withMessages([
                    'inventory_items' => 'One or more offered inventory items is missing from escrow.',
                ]);
            }

            if ($inventoryItem->quantity < $quantity) {
                throw ValidationException::withMessages([
                    'inventory_items' => 'One or more offered inventory items does not have enough quantity.',
                ]);
            }
        }
    }

    /**
     * @param  Collection<int, int>  $requestedQuantities
     * @param  Collection<int, InventoryItem>  $inventoryItems
     */
    private function validateRequestedItemsForAccept(
        Collection $requestedQuantities,
        Collection $inventoryItems,
        Trade $trade
    ): void {
        foreach ($requestedQuantities as $inventoryItemId => $quantity) {
            $inventoryItem = $inventoryItems->get($inventoryItemId);

            if ((int) $inventoryItem->user_id !== (int) $trade->recipient_id) {
                throw ValidationException::withMessages([
                    'inventory_items' => 'One or more requested inventory items is no longer owned by the recipient.',
                ]);
            }

            if (! $inventoryItem->is_tradable) {
                throw ValidationException::withMessages([
                    'inventory_items' => 'One or more requested inventory items is no longer tradable.',
                ]);
            }

            if ($inventoryItem->is_in_escrow) {
                throw ValidationException::withMessages([
                    'inventory_items' => 'One or more requested inventory items is already in escrow.',
                ]);
            }

            if ($inventoryItem->quantity < $quantity) {
                throw ValidationException::withMessages([
                    'inventory_items' => 'One or more requested inventory items does not have enough quantity.',
                ]);
            }
        }
    }

    private function transferInventoryItem(InventoryItem $inventoryItem, int $toUserId, int $quantity): void
    {
        if ($inventoryItem->quantity === $quantity) {
            $inventoryItem->forceFill([
                'user_id' => $toUserId,
                'is_in_escrow' => false,
            ])->save();

            return;
        }

        $inventoryItem->forceFill([
            'quantity' => $inventoryItem->quantity - $quantity,
            'is_in_escrow' => false,
        ])->save();

        InventoryItem::query()->create([
            'user_id' => $toUserId,
            'item_id' => $inventoryItem->item_id,
            'quantity' => $quantity,
            'is_tradable' => $inventoryItem->is_tradable,
            'is_in_escrow' => false,
        ]);
    }
}
