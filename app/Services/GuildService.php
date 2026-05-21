<?php

namespace App\Services;

use App\Models\Guild;
use App\Models\GuildEvent;
use App\Models\GuildInvite;
use App\Models\GuildMember;
use App\Models\User;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
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
    public function listGuilds(): LengthAwarePaginator
    {
        return Guild::query()
            ->withCount('users')
            ->latest('id')
            ->paginate(15);
    }

    public function getGuild(Guild $guild): Guild
    {
        return $guild->loadCount('users');
    }

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

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateGuild(Guild $guild, array $data): Guild
    {
        return DB::transaction(function () use ($guild, $data): Guild {
            $guild->fill([
                'name' => $data['name'] ?? $guild->name,
                'description' => array_key_exists('description', $data)
                    ? $data['description']
                    : $guild->description,
                'is_open' => $data['is_open'] ?? $guild->is_open,
            ])->save();

            return $guild->refresh();
        });
    }

    public function deleteGuild(Guild $guild): void
    {
        DB::transaction(function () use ($guild): void {
            $guild->delete();
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

            GuildMember::query()
                ->where('guild_id', $guild->id)
                ->where('user_id', $user->id)
                ->firstOrFail()
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

            GuildMember::query()
                ->where('guild_id', $guild->id)
                ->where('user_id', $target->id)
                ->firstOrFail()
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

            $targetMember = GuildMember::query()
                ->where('guild_id', $guild->id)
                ->where('user_id', $target->id)
                ->firstOrFail();

            $targetMember->forceFill(['role' => $role])->save();
        });
    }

    public function depositTreasury(Guild $guild, User $actor, int $amount): void
    {
        DB::transaction(function () use ($guild, $actor, $amount): void {
            Guild::query()
                ->whereKey($guild->id)
                ->lockForUpdate()
                ->firstOrFail();

            $membership = DB::table('guild_user')
                ->where('guild_id', $guild->id)
                ->where('user_id', $actor->id)
                ->lockForUpdate()
                ->first();

            if ($membership === null) {
                throw ValidationException::withMessages([
                    'guild' => 'User is not a member of this guild.',
                ]);
            }

            Guild::query()
                ->whereKey($guild->id)
                ->increment('treasury_balance', $amount);

            DB::table('guild_user')
                ->where('guild_id', $guild->id)
                ->where('user_id', $actor->id)
                ->increment('contributed_gold', $amount);
        });
    }

    public function withdrawTreasury(Guild $guild, User $actor, int $amount, string $reason): void
    {
        DB::transaction(function () use ($guild, $actor, $amount, $reason): void {
            $lockedGuild = Guild::query()
                ->whereKey($guild->id)
                ->lockForUpdate()
                ->firstOrFail();

            $membership = DB::table('guild_user')
                ->where('guild_id', $guild->id)
                ->where('user_id', $actor->id)
                ->lockForUpdate()
                ->first();

            if ($membership?->role !== 'leader') {
                throw ValidationException::withMessages([
                    'guild' => 'Only guild leaders can withdraw from the treasury.',
                ]);
            }

            if ($lockedGuild->treasury_balance < $amount) {
                throw ValidationException::withMessages([
                    'amount' => 'Guild treasury has insufficient funds.',
                ]);
            }

            $balanceBefore = $lockedGuild->treasury_balance;
            $balanceAfter = $balanceBefore - $amount;

            Guild::query()
                ->whereKey($guild->id)
                ->update(['treasury_balance' => $balanceAfter]);

            GuildEvent::query()->create([
                'guild_id' => $guild->id,
                'actor_id' => $actor->id,
                'event_type' => 'withdraw',
                'metadata' => [
                    'amount' => $amount,
                    'reason' => $reason,
                    'balance_before' => $balanceBefore,
                    'balance_after' => $balanceAfter,
                ],
            ]);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function createInvite(Guild $guild, User $inviter, array $data): GuildInvite
    {
        return DB::transaction(function () use ($guild, $inviter, $data): GuildInvite {
            $email = $data['email'];
            $invitedUser = User::query()
                ->where('email', $email)
                ->first();

            if ($invitedUser !== null && $guild->users()->whereKey($invitedUser->id)->exists()) {
                throw ValidationException::withMessages([
                    'email' => 'User is already a member of this guild.',
                ]);
            }

            return GuildInvite::query()->create([
                'guild_id' => $guild->id,
                'invited_by' => $inviter->id,
                'email' => $email,
                'token' => (string) Str::uuid(),
                'expires_at' => now()->addHours(48),
            ]);
        });
    }

    public function acceptInvite(GuildInvite $invite, User $user): void
    {
        DB::transaction(function () use ($invite, $user): void {
            $lockedInvite = GuildInvite::query()
                ->whereKey($invite->id)
                ->lockForUpdate()
                ->first();

            if ($lockedInvite === null) {
                throw ValidationException::withMessages([
                    'token' => 'Guild invite does not exist.',
                ]);
            }

            if ($lockedInvite->expires_at->isPast()) {
                throw ValidationException::withMessages([
                    'token' => 'Guild invite has expired.',
                ]);
            }

            if ($lockedInvite->accepted_at !== null) {
                throw ValidationException::withMessages([
                    'token' => 'Guild invite has already been accepted.',
                ]);
            }

            $memberships = DB::table('guild_user')
                ->where('user_id', $user->id)
                ->lockForUpdate()
                ->get();

            if ($memberships->contains('guild_id', $lockedInvite->guild_id)) {
                throw ValidationException::withMessages([
                    'guild' => 'User is already a member of this guild.',
                ]);
            }

            if ($memberships->count() >= 5) {
                throw ValidationException::withMessages([
                    'guild' => 'User cannot belong to more than 5 guilds.',
                ]);
            }

            GuildMember::query()->create([
                'guild_id' => $lockedInvite->guild_id,
                'user_id' => $user->id,
                'role' => 'member',
                'joined_at' => now(),
                'contributed_gold' => 0,
            ]);

            $lockedInvite->forceFill([
                'accepted_at' => now(),
            ])->save();
        });
    }

    public function guildEvents(Guild $guild): LengthAwarePaginator
    {
        return $guild->events()
            ->latest('id')
            ->paginate(15);
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
