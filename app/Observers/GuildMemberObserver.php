<?php

namespace App\Observers;

use App\Models\GuildEvent;
use App\Models\GuildMember;

class GuildMemberObserver
{
    public function created(GuildMember $guildMember): void
    {
        $this->recordMembershipEvent($guildMember, 'join', [
            'role' => $guildMember->role,
            'joined_at' => $guildMember->joined_at?->toISOString(),
        ]);
    }

    public function updated(GuildMember $guildMember): void
    {
        if (! $guildMember->wasChanged('role')) {
            return;
        }

        $fromRole = $guildMember->getOriginal('role');
        $toRole = $guildMember->role;
        $eventType = $this->roleRank($toRole) > $this->roleRank($fromRole)
            ? 'promote'
            : 'demote';

        $this->recordMembershipEvent($guildMember, $eventType, [
            'from_role' => $fromRole,
            'to_role' => $toRole,
        ]);
    }

    public function deleted(GuildMember $guildMember): void
    {
        $this->recordMembershipEvent($guildMember, 'leave', [
            'role' => $guildMember->role,
            'joined_at' => $guildMember->joined_at?->toISOString(),
            'contributed_gold' => $guildMember->contributed_gold,
        ]);
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function recordMembershipEvent(GuildMember $guildMember, string $eventType, array $metadata): void
    {
        GuildEvent::query()->create([
            'guild_id' => $guildMember->guild_id,
            'target_id' => $guildMember->user_id,
            'event_type' => $eventType,
            'metadata' => $metadata,
        ]);
    }

    private function roleRank(string $role): int
    {
        return match ($role) {
            'leader' => 3,
            'officer' => 2,
            'member' => 1,
            default => 0,
        };
    }
}
