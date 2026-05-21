<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class GuildBonusService
{
    public function getMultiplierForUser(int $userId): float
    {
        // L7 guild leader bonuses are intentionally global for loot rolls:
        // a user who leads any guild receives the configured legendary multiplier.
        $isLeaderInAnyGuild = DB::table('guild_user')
            ->where('user_id', $userId)
            ->where('role', 'leader')
            ->exists();

        if (! $isLeaderInAnyGuild) {
            return 1.0;
        }

        return (float) config('loot.guild_leader_legendary_multiplier', 2.0);
    }
}
