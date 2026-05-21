<?php

namespace App\Services;

use App\Models\Trade;
use App\Models\TradeItem;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class TradeService
{
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
}
