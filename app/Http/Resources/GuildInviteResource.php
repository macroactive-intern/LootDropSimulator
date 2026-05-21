<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class GuildInviteResource extends JsonResource
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
            'guild_id' => $this->guild_id,
            'invited_by' => $this->invited_by,
            'email' => $this->email,
            'token' => $this->token,
            'accepted_at' => $this->accepted_at?->toISOString(),
            'expires_at' => $this->expires_at?->toISOString(),
        ];
    }
}
