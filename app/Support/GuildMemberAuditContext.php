<?php

namespace App\Support;

class GuildMemberAuditContext
{
    /**
     * @var array<string, array{actor_id: int|null}>
     */
    private array $creations = [];

    /**
     * @var array<string, array{actor_id: int|null}>
     */
    private array $updates = [];

    /**
     * @var array<string, array{event_type: string, actor_id: int|null}>
     */
    private array $deletions = [];

    public function rememberCreation(int $guildId, int $userId, ?int $actorId = null): void
    {
        $this->creations[$this->key($guildId, $userId)] = [
            'actor_id' => $actorId,
        ];
    }

    /**
     * @return array{actor_id: int|null}|null
     */
    public function consumeCreation(int $guildId, int $userId): ?array
    {
        $key = $this->key($guildId, $userId);
        $context = $this->creations[$key] ?? null;

        unset($this->creations[$key]);

        return $context;
    }

    public function rememberUpdate(int $guildId, int $userId, ?int $actorId = null): void
    {
        $this->updates[$this->key($guildId, $userId)] = [
            'actor_id' => $actorId,
        ];
    }

    /**
     * @return array{actor_id: int|null}|null
     */
    public function consumeUpdate(int $guildId, int $userId): ?array
    {
        $key = $this->key($guildId, $userId);
        $context = $this->updates[$key] ?? null;

        unset($this->updates[$key]);

        return $context;
    }

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

    public function forgetCreation(int $guildId, int $userId): void
    {
        unset($this->creations[$this->key($guildId, $userId)]);
    }

    public function forgetUpdate(int $guildId, int $userId): void
    {
        unset($this->updates[$this->key($guildId, $userId)]);
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
