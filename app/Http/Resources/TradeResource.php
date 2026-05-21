<?php

namespace App\Http\Resources;

use App\Models\EscrowItem;
use App\Models\TradeItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TradeResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $this->resource->loadMissing([
            'tradeItems.inventoryItem.item',
            'escrowItems.inventoryItem.item',
        ]);

        return [
            'id' => $this->id,
            'initiator_id' => $this->initiator_id,
            'recipient_id' => $this->recipient_id,
            'guild_id' => $this->guild_id,
            'status' => $this->status,
            'expires_at' => $this->expires_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
            'offered_items' => $this->tradeItemsForUser($this->initiator_id),
            'requested_items' => $this->tradeItemsForUser($this->recipient_id),
            'escrow_items' => $this->escrowItemsData(),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function tradeItemsForUser(int $userId): array
    {
        return $this->tradeItems
            ->where('from_user_id', $userId)
            ->values()
            ->map(fn (TradeItem $tradeItem): array => $this->tradeItemData($tradeItem))
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function escrowItemsData(): array
    {
        return $this->escrowItems
            ->values()
            ->map(fn (EscrowItem $escrowItem): array => $this->escrowItemData($escrowItem))
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function tradeItemData(TradeItem $tradeItem): array
    {
        $inventoryItem = $tradeItem->inventoryItem;
        $item = $inventoryItem?->item;

        return [
            'trade_item_id' => $tradeItem->id,
            'inventory_item_id' => $tradeItem->inventory_item_id,
            'item_id' => $inventoryItem?->item_id,
            'item_name' => $item?->name,
            'base_value' => $item?->base_value,
            'is_unique' => $item?->is_unique,
            'from_user_id' => $tradeItem->from_user_id,
            'quantity' => $tradeItem->quantity,
            'total_value' => $this->totalValue($item?->base_value, $tradeItem->quantity),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function escrowItemData(EscrowItem $escrowItem): array
    {
        $inventoryItem = $escrowItem->inventoryItem;
        $item = $inventoryItem?->item;

        return [
            'escrow_item_id' => $escrowItem->id,
            'inventory_item_id' => $escrowItem->inventory_item_id,
            'item_id' => $inventoryItem?->item_id,
            'item_name' => $item?->name,
            'base_value' => $item?->base_value,
            'is_unique' => $item?->is_unique,
            'quantity' => $escrowItem->quantity,
            'total_value' => $this->totalValue($item?->base_value, $escrowItem->quantity),
        ];
    }

    private function totalValue(?int $baseValue, int $quantity): ?int
    {
        if ($baseValue === null) {
            return null;
        }

        return $baseValue * $quantity;
    }
}
