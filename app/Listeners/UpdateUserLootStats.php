<?php

namespace App\Listeners;

use App\Events\LootDropped;
use App\Models\UserLootStat;

class UpdateUserLootStats
{
    /**
     * Create the event listener.
     */
    public function __construct()
    {
        //
    }

    /**
     * Handle the event.
     */
    public function handle(LootDropped $event): void
    {
        $droppedItem = $event->droppedItem;

        $stats = UserLootStat::query()->updateOrCreate(
            ['user_id' => $droppedItem->user_id],
            ['last_drop_at' => now()]
        );

        $stats->increment('total_drops');

        if ($droppedItem->rarity === 'legendary') {
            $stats->increment('legendary_count');
        }
    }
}
