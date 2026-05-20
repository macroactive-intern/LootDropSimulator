<?php

namespace App\Listeners;

use App\Events\LootDropped;
use Illuminate\Support\Facades\Log;

class LogLootDrop
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

        Log::info('Loot dropped', [
            'dropped_item_id' => $droppedItem->id,
            'user_id' => $droppedItem->user_id,
            'item_name' => $droppedItem->item_name,
            'rarity' => $droppedItem->rarity,
            'source' => $droppedItem->source,
            'quantity' => $droppedItem->quantity,
        ]);
    }
}
