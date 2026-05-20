<?php

namespace App\Services;

use App\Events\LootDropped;
use App\Models\DroppedItem;
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
        $loot = $this->lootTable->roll($legendaryMultiplier);

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
}
