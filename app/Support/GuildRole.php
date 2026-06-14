<?php

declare(strict_types=1);

namespace App\Support;

class GuildRole
{
    public static function rank(string $role): int
    {
        return match ($role) {
            'leader' => 3,
            'officer' => 2,
            'member' => 1,
            default => 0,
        };
    }
}
