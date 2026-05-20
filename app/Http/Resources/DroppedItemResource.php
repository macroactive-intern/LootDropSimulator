<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DroppedItemResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'item_name' => $this->item_name,
            'rarity' => $this->rarity,
            'source' => $this->source,
            'quantity' => $this->quantity,
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
