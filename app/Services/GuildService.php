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
        DB::transaction(function () use ($guild, $user): void {
            $members = DB::table('guild_user')
                ->where('guild_id', $guild->id)
                ->lockForUpdate()
                ->get();

            $membership = $members->firstWhere('user_id', $user->id);

            if ($membership === null) {
                throw ValidationException::withMessages([
                    'guild' => 'User is not a member of this guild.',
                ]);
            }

            $leaderCount = $members
                ->where('role', 'leader')
                ->count();

            if ($membership->role === 'leader' && $leaderCount <= 1) {
                throw ValidationException::withMessages([
                    'guild' => 'A guild must always have at least one leader.',
                ]);
            }

            DB::table('guild_user')
                ->where('guild_id', $guild->id)
                ->where('user_id', $user->id)
                ->delete();
        });
    }

    public function kickMember(Guild $guild, User $actor, User $target): void
    {
        DB::transaction(function () use ($guild, $actor, $target): void {
            $members = DB::table('guild_user')
                ->where('guild_id', $guild->id)
                ->lockForUpdate()
                ->get();

            $actorMembership = $members->firstWhere('user_id', $actor->id);
            $targetMembership = $members->firstWhere('user_id', $target->id);

            if ($actorMembership === null) {
                throw ValidationException::withMessages([
                    'guild' => 'Only guild leaders and officers can kick members.',
                ]);
            }

            if ($targetMembership === null) {
                throw ValidationException::withMessages([
                    'guild' => 'Target user is not a member of this guild.',
                ]);
            }

            if ($actorMembership->role === 'officer' && $targetMembership->role !== 'member') {
                throw ValidationException::withMessages([
                    'guild' => 'Officers can only kick members.',
                ]);
            }

            if (! in_array($actorMembership->role, ['leader', 'officer'], true)) {
                throw ValidationException::withMessages([
                    'guild' => 'Only guild leaders and officers can kick members.',
                ]);
            }

            $leaderCount = $members
                ->where('role', 'leader')
                ->count();

            if ($targetMembership->role === 'leader' && $leaderCount <= 1) {
                throw ValidationException::withMessages([
                    'guild' => 'A guild must always have at least one leader.',
                ]);
            }

            DB::table('guild_user')
                ->where('guild_id', $guild->id)
                ->where('user_id', $target->id)
                ->delete();
        });
    }

    public function changeRole(Guild $guild, User $actor, User $target, string $role): void
    {
        DB::transaction(function () use ($guild, $actor, $target, $role): void {
            if (! in_array($role, ['leader', 'officer', 'member'], true)) {
                throw ValidationException::withMessages([
                    'role' => 'Invalid guild role.',
                ]);
            }

            $members = DB::table('guild_user')
                ->where('guild_id', $guild->id)
                ->lockForUpdate()
                ->get();

            $actorMembership = $members->firstWhere('user_id', $actor->id);
            $targetMembership = $members->firstWhere('user_id', $target->id);

            if ($actorMembership?->role !== 'leader') {
                throw ValidationException::withMessages([
                    'guild' => 'Only guild leaders can change member roles.',
                ]);
            }

            if ($targetMembership === null) {
                throw ValidationException::withMessages([
                    'guild' => 'Target user is not a member of this guild.',
                ]);
            }

            if ($targetMembership->role === $role) {
                throw ValidationException::withMessages([
                    'role' => 'Target user already has this role.',
                ]);
            }

            if (! $this->isValidRoleTransition($targetMembership->role, $role)) {
                throw ValidationException::withMessages([
                    'role' => 'Invalid guild role transition.',
                ]);
            }

            $leaderCount = $members
                ->where('role', 'leader')
                ->count();

            if ($targetMembership->role === 'leader' && $role !== 'leader' && $leaderCount <= 1) {
                throw ValidationException::withMessages([
                    'guild' => 'A guild must always have at least one leader.',
                ]);
            }

            DB::table('guild_user')
                ->where('guild_id', $guild->id)
                ->where('user_id', $target->id)
                ->update(['role' => $role]);
        });
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

    private function isValidRoleTransition(string $fromRole, string $toRole): bool
    {
        return match ($fromRole) {
            'member' => $toRole === 'officer',
            'officer' => in_array($toRole, ['leader', 'member'], true),
            'leader' => $toRole === 'officer',
            default => false,
        };
    }
}
