<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class GuildResource extends JsonResource
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
            'name' => $this->name,
            'description' => $this->description,
            'treasury_balance' => $this->treasury_balance,
            'is_open' => $this->is_open,
            'member_count' => $this->memberCount(),
            'current_user_role' => $this->currentUserRole($request->user()),
        ];
    }

    private function memberCount(): int
    {
        if (isset($this->users_count)) {
            return (int) $this->users_count;
        }

        if ($this->relationLoaded('users')) {
            return $this->users->count();
        }

        return 0;
    }

    private function currentUserRole(?User $user): ?string
    {
        if (array_key_exists('current_user_role', $this->resource->getAttributes())) {
            return $this->current_user_role;
        }

        if ($user === null) {
            return null;
        }

        if ($this->relationLoaded('users')) {
            return $this->users
                ->firstWhere('id', $user->id)
                ?->pivot
                ?->role;
        }

        return null;
    }
}
