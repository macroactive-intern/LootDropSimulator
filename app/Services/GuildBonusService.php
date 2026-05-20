<?php

namespace App\Services;

class GuildBonusService
{
    public function getMultiplierForUser(int $userId): float
    {
        // Stub for L7 guild leader bonuses. Once guild membership and rank data
        // exist, this method can check whether the user is a guild leader and
        // return the configured 2.0 legendary multiplier for eligible users.
        return 1.0;
    }
}
