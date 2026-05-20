<?php

namespace App\Services;

use App\Events\LootDropped;
use App\Models\DroppedItem;
use App\Models\UserLootStat;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\DB;

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
        return DB::transaction(function () use ($userId, $source, $legendaryMultiplier): DroppedItem {
            $stats = UserLootStat::query()
                ->where('user_id', $userId)
                ->lockForUpdate()
                ->first();

            $loot = $this->shouldForceRareOrHigher($stats)
                ? $this->rollRareOrHigher($legendaryMultiplier)
                : $this->lootTable->roll($legendaryMultiplier);

            $droppedItem = DroppedItem::query()->create([
                'user_id' => $userId,
                'item_name' => $loot['name'],
                'rarity' => $loot['rarity'],
                'source' => $source,
                'quantity' => $this->quantityForLoot($loot),
            ]);

            $this->events->dispatch(new LootDropped($droppedItem));

            return $droppedItem;
        });
    }

    /**
     * @param  array<string, mixed>  $loot
     */
    private function quantityForLoot(array $loot): int
    {
        if (! ($loot['stackable'] ?? false)) {
            return 1;
        }

        return random_int(1, max(1, (int) ($loot['max_stack'] ?? 1)));
    }

    private function shouldForceRareOrHigher(?UserLootStat $stats): bool
    {
        return ($stats?->consecutive_common_drops ?? 0) >= 10;
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
