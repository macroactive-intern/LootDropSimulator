<?php

namespace App\Services;

use App\Models\Guild;
use App\Models\GuildInvite;
use App\Models\User;
use Illuminate\Contracts\Events\Dispatcher;

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
        //
    }

    public function joinGuild(Guild $guild, User $user): void
    {
        //
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
