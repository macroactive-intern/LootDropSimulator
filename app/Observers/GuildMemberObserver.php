<?php

namespace App\Observers;

use App\Models\GuildEvent;
use App\Models\GuildMember;
use App\Support\GuildMemberAuditContext;
use App\Support\GuildRole;

class GuildMemberObserver
{
    public function created(GuildMember $guildMember): void
    {
        $creationContext = app(GuildMemberAuditContext::class)
            ->consumeCreation($guildMember->guild_id, $guildMember->user_id);

        $this->recordMembershipEvent($guildMember, 'join', [
            'role' => $guildMember->role,
            'joined_at' => $guildMember->joined_at?->toISOString(),
        ], $creationContext['actor_id'] ?? null);
    }

    public function updated(GuildMember $guildMember): void
    {
        if (! $guildMember->wasChanged('role')) {
            return;
        }

        $fromRole = $guildMember->getOriginal('role');
        $toRole = $guildMember->role;
        $eventType = GuildRole::rank($toRole) > GuildRole::rank($fromRole)
            ? 'promote'
            : 'demote';
        $updateContext = app(GuildMemberAuditContext::class)
            ->consumeUpdate($guildMember->guild_id, $guildMember->user_id);

        $this->recordMembershipEvent($guildMember, $eventType, [
            'from_role' => $fromRole,
            'to_role' => $toRole,
        ], $updateContext['actor_id'] ?? null);
    }

    public function deleted(GuildMember $guildMember): void
    {
        $deletionContext = app(GuildMemberAuditContext::class)
            ->consumeDeletion($guildMember->guild_id, $guildMember->user_id);

        $this->recordMembershipEvent($guildMember, $deletionContext['event_type'] ?? 'leave', [
            'role' => $guildMember->role,
            'joined_at' => $guildMember->joined_at?->toISOString(),
            'contributed_gold' => $guildMember->contributed_gold,
        ], $deletionContext['actor_id'] ?? null);
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function recordMembershipEvent(
        GuildMember $guildMember,
        string $eventType,
        array $metadata,
        ?int $actorId = null
    ): void {
        GuildEvent::query()->create([
            'guild_id' => $guildMember->guild_id,
            'actor_id' => $actorId,
            'target_id' => $guildMember->user_id,
            'event_type' => $eventType,
            'metadata' => $metadata,
        ]);
    }

}
