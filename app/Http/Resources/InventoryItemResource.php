<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InventoryItemResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $this->resource->loadMissing('item');

        return [
            'id' => $this->id,
            'item_id' => $this->item_id,
            'item_name' => $this->item?->name,
            'description' => $this->item?->description,
            'base_value' => $this->item?->base_value,
            'is_unique' => $this->item?->is_unique,
            'quantity' => $this->quantity,
            'is_tradable' => $this->is_tradable,
            'is_in_escrow' => $this->is_in_escrow,
        ];
    }
}
