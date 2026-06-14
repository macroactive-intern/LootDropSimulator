<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserLootStatResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'user_id' => $this->user_id,
            'total_drops' => $this->total_drops ?? 0,
            'legendary_count' => $this->legendary_count ?? 0,
            'consecutive_common_drops' => $this->consecutive_common_drops ?? 0,
            'last_drop_at' => $this->last_drop_at?->toISOString(),
        ];
    }
}
