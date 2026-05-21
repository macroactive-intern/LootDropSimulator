<?php

namespace App\Services;

use App\Models\EscrowItem;
use App\Models\InventoryItem;
use App\Models\Trade;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;

class EscrowService
{
    /**
     * @param  array<int, array<string, int>>  $tradeItems
     */
    public function lockItemsForTrade(Trade $trade, array $tradeItems): void
    {
        $this->ensureExistingTransaction();

        $requestedQuantities = $this->requestedQuantities($tradeItems);

        if ($requestedQuantities->isEmpty()) {
            return;
        }

        $inventoryItems = InventoryItem::query()
            ->whereIn('id', $requestedQuantities->keys()->all())
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        $this->validateInventoryItemsForLock($requestedQuantities, $inventoryItems);

        foreach ($inventoryItems as $inventoryItem) {
            $inventoryItem->forceFill(['is_in_escrow' => true])->save();
        }

        foreach ($requestedQuantities as $inventoryItemId => $quantity) {
            EscrowItem::query()->create([
                'trade_id' => $trade->id,
                'inventory_item_id' => $inventoryItemId,
                'quantity' => $quantity,
            ]);
        }
    }

    public function releaseEscrow(Trade $trade): void
    {
        $this->ensureExistingTransaction();

        $escrowItems = $trade->escrowItems()
            ->lockForUpdate()
            ->get();

        if ($escrowItems->isEmpty()) {
            return;
        }

        $inventoryItems = InventoryItem::query()
            ->whereIn('id', $escrowItems->pluck('inventory_item_id')->unique()->all())
            ->lockForUpdate()
            ->get();

        foreach ($inventoryItems as $inventoryItem) {
            $inventoryItem->forceFill(['is_in_escrow' => false])->save();
        }

        $trade->escrowItems()->delete();
    }

    /**
     * @param  Collection<int, int>  $requestedQuantities
     * @param  Collection<int, InventoryItem>  $inventoryItems
     */
    private function validateInventoryItemsForLock(Collection $requestedQuantities, Collection $inventoryItems): void
    {
        $missingInventoryItemIds = $requestedQuantities
            ->keys()
            ->diff($inventoryItems->keys());

        if ($missingInventoryItemIds->isNotEmpty()) {
            throw ValidationException::withMessages([
                'inventory_items' => 'One or more selected inventory items no longer exists.',
            ]);
        }

        foreach ($requestedQuantities as $inventoryItemId => $quantity) {
            $inventoryItem = $inventoryItems->get($inventoryItemId);

            if ($inventoryItem->is_in_escrow) {
                throw ValidationException::withMessages([
                    'inventory_items' => 'One or more selected inventory items is already in escrow.',
                ]);
            }

            if ($inventoryItem->quantity < $quantity) {
                throw ValidationException::withMessages([
                    'inventory_items' => 'One or more selected inventory items does not have enough quantity.',
                ]);
            }
        }
    }

    /**
     * @param  array<int, array<string, int>>  $tradeItems
     * @return Collection<int, int>
     */
    private function requestedQuantities(array $tradeItems): Collection
    {
        return collect($tradeItems)
            ->groupBy(fn (array $tradeItem): int => (int) $tradeItem['inventory_item_id'])
            ->map(fn (Collection $items): int => $items->sum(
                fn (array $tradeItem): int => (int) $tradeItem['quantity']
            ));
    }

    private function ensureExistingTransaction(): void
    {
        if (DB::connection()->transactionLevel() === 0) {
            throw new LogicException('Escrow operations must run inside an existing database transaction.');
        }
    }
}
