<?php

namespace App\Http\Resources;

use App\Models\GuildMember;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class GuildMemberResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $member = $this->resource instanceof GuildMember
            ? $this->resource->user
            : $this->resource;
        $pivot = $this->resource instanceof GuildMember
            ? $this->resource
            : $this->pivot;

        return [
            'id' => $member?->id,
            'name' => $member?->name,
            'role' => $pivot?->role,
            'joined_at' => $pivot?->joined_at?->toISOString(),
            'contributed_gold' => $pivot?->contributed_gold,
        ];
    }
}
