<?php

namespace App\Services;

use App\Models\Guild;
use App\Models\GuildInvite;
use App\Models\User;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class GuildService
{
    public function __construct(
        private readonly Dispatcher $events,
    ) {
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function createGuild(User $creator, array $data): Guild
    {
        return DB::transaction(function () use ($creator, $data): Guild {
            $guild = Guild::query()->create([
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'created_by' => $creator->id,
                'treasury_balance' => $data['treasury_balance'] ?? 0,
                'is_open' => $data['is_open'] ?? false,
            ]);

            $guild->users()->attach($creator->id, [
                'role' => 'leader',
                'joined_at' => now(),
            ]);

            return $guild;
        });
    }

    public function joinGuild(Guild $guild, User $user): void
    {
        DB::transaction(function () use ($guild, $user): void {
            if (! $guild->is_open) {
                throw ValidationException::withMessages([
                    'guild' => 'Only open guilds can be joined directly.',
                ]);
            }

            if ($guild->users()->whereKey($user->id)->exists()) {
                throw ValidationException::withMessages([
                    'guild' => 'User is already a member of this guild.',
                ]);
            }

            if ($user->guilds()->count() >= 5) {
                throw ValidationException::withMessages([
                    'guild' => 'User cannot belong to more than 5 guilds.',
                ]);
            }

            $guild->users()->attach($user->id, [
                'role' => 'member',
                'joined_at' => now(),
            ]);
        });
    }

    public function leaveGuild(Guild $guild, User $user): void
    {
        //
    }

    public function kickMember(Guild $guild, User $actor, User $target): void
    {
        //
    }

    public function changeRole(Guild $guild, User $actor, User $target, string $role): void
    {
        //
    }

    public function depositTreasury(Guild $guild, User $actor, int $amount): void
    {
        //
    }

    public function withdrawTreasury(Guild $guild, User $actor, int $amount, string $reason): void
    {
        //
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function createInvite(Guild $guild, User $inviter, array $data): GuildInvite
    {
        //
    }

    public function acceptInvite(GuildInvite $invite, User $user): void
    {
        //
    }
}
