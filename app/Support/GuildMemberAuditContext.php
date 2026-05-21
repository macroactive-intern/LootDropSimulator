<?php

namespace App\Support;

class GuildMemberAuditContext
{
    /**
     * @var array<string, array{event_type: string, actor_id: int|null}>
     */
    private array $deletions = [];

    public function rememberDeletion(int $guildId, int $userId, string $eventType, ?int $actorId = null): void
    {
        $this->deletions[$this->key($guildId, $userId)] = [
            'event_type' => $eventType,
            'actor_id' => $actorId,
        ];
    }

    /**
     * @return array{event_type: string, actor_id: int|null}|null
     */
    public function consumeDeletion(int $guildId, int $userId): ?array
    {
        $key = $this->key($guildId, $userId);
        $context = $this->deletions[$key] ?? null;

        unset($this->deletions[$key]);

        return $context;
    }

    public function forgetDeletion(int $guildId, int $userId): void
    {
        unset($this->deletions[$this->key($guildId, $userId)]);
    }

    private function key(int $guildId, int $userId): string
    {
        return $guildId.':'.$userId;
    }
}
