<?php

namespace App\Policies;

use App\Models\Guild;
use App\Models\User;

class GuildPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Guild $guild): bool
    {
        return $guild->is_open || $this->roleFor($user, $guild) !== null;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Guild $guild): bool
    {
        return $this->roleFor($user, $guild) === 'leader';
    }

    public function delete(User $user, Guild $guild): bool
    {
        return $guild->created_by === $user->id
            && $this->roleFor($user, $guild) === 'leader';
    }

    public function invite(User $user, Guild $guild): bool
    {
        return in_array($this->roleFor($user, $guild), ['leader', 'officer'], true);
    }

    public function kick(User $user, Guild $guild, User $target): bool
    {
        $role = $this->roleFor($user, $guild);

        if ($role === 'leader') {
            return $target->id !== $user->id
                && $this->roleFor($target, $guild) !== null;
        }

        return $role === 'officer'
            && $this->roleFor($target, $guild) === 'member';
    }

    public function promote(User $user, Guild $guild, User $target): bool
    {
        return $this->roleFor($user, $guild) === 'leader'
            && in_array($this->roleFor($target, $guild), ['member', 'officer'], true);
    }

    public function demote(User $user, Guild $guild, User $target): bool
    {
        return $this->roleFor($user, $guild) === 'leader'
            && in_array($this->roleFor($target, $guild), ['leader', 'officer'], true);
    }

    public function changeRole(User $user, Guild $guild, User $target): bool
    {
        return $this->roleFor($user, $guild) === 'leader'
            && $this->roleFor($target, $guild) !== null;
    }

    public function deposit(User $user, Guild $guild): bool
    {
        return $this->roleFor($user, $guild) !== null;
    }

    public function join(User $user, Guild $guild): bool
    {
        return true;
    }

    public function leave(User $user, Guild $guild): bool
    {
        return $this->roleFor($user, $guild) !== null;
    }

    public function withdraw(User $user, Guild $guild): bool
    {
        return $this->roleFor($user, $guild) === 'leader';
    }

    public function viewEvents(User $user, Guild $guild): bool
    {
        return in_array($this->roleFor($user, $guild), ['leader', 'officer'], true);
    }

    private function roleFor(User $user, Guild $guild): ?string
    {
        return $guild->users()
            ->whereKey($user->id)
            ->first()
            ?->pivot
            ?->role;
    }
}
