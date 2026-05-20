<?php

namespace App\Services;

use App\Events\LootDropped;
use App\Models\DroppedItem;
use App\Models\UserLootStat;
use Illuminate\Contracts\Events\Dispatcher;

class LootService
{
    public function __construct(
        private readonly LootTable $lootTable,
        private readonly Dispatcher $events,
    ) {
    }

    public function roll(
        int $userId,
        string $source,
        float $legendaryMultiplier = 1.0
    ): DroppedItem {
        $loot = $this->shouldForceRareOrHigher($userId)
            ? $this->rollRareOrHigher($legendaryMultiplier)
            : $this->lootTable->roll($legendaryMultiplier);

        $droppedItem = DroppedItem::query()->create([
            'user_id' => $userId,
            'item_name' => $loot['name'],
            'rarity' => $loot['rarity'],
            'source' => $source,
            'quantity' => $loot['stackable'] ? random_int(1, (int) $loot['max_stack']) : 1,
        ]);

        $this->events->dispatch(new LootDropped($droppedItem));

        return $droppedItem;
    }

    private function shouldForceRareOrHigher(int $userId): bool
    {
        return UserLootStat::query()
            ->where('user_id', $userId)
            ->where('consecutive_common_drops', '>=', 10)
            ->exists();
    }

    /**
     * After 10 consecutive commons, the next roll uses the same weighted
     * algorithm but excludes common items from the temporary loot pool.
     *
     * @return array<string, mixed>
     */
    private function rollRareOrHigher(float $legendaryMultiplier): array
    {
        $items = collect(config('loot.items', []))
            ->reject(fn (array $item): bool => ($item['rarity'] ?? null) === 'common')
            ->values()
            ->all();

        return (new LootTable($items))->roll($legendaryMultiplier);
    }
}
