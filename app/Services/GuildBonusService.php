<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class GuildBonusService
{
    public function getMultiplierForUser(int $userId): float
    {
        $isGuildLeader = DB::table('guild_user')
            ->where('user_id', $userId)
            ->where('role', 'leader')
            ->exists();

        if (! $isGuildLeader) {
            return 1.0;
        }

        return (float) config('loot.guild_leader_legendary_multiplier', 2.0);
    }
}
