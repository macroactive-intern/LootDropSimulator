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

        $stats = UserLootStat::query()
            ->where('user_id', $droppedItem->user_id)
            ->lockForUpdate()
            ->firstOrNew(['user_id' => $droppedItem->user_id]);

        $stats->forceFill([
            'total_drops' => $stats->total_drops + 1,
            'legendary_count' => $stats->legendary_count + ($droppedItem->rarity === 'legendary' ? 1 : 0),
            'consecutive_common_drops' => $droppedItem->rarity === 'common'
                ? $stats->consecutive_common_drops + 1
                : 0,
            'last_drop_at' => now(),
        ])->save();
    }
}
