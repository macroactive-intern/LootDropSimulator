<?php

use App\Http\Resources\DroppedItemResource;
use App\Models\DroppedItem;
use Illuminate\Http\Request;

test('it formats a dropped item for api responses', function (): void {
    $droppedItem = new DroppedItem([
        'item_name' => 'Common Sword',
        'rarity' => 'common',
        'source' => 'daily_reward',
        'quantity' => 1,
    ]);
    $droppedItem->id = 123;
    $droppedItem->created_at = now();

    $resource = (new DroppedItemResource($droppedItem))->toArray(new Request());

    expect(array_keys($resource))->toBe([
        'id',
        'item_name',
        'rarity',
        'source',
        'quantity',
        'created_at',
    ])
        ->and($resource['id'])->toBe(123)
        ->and($resource['item_name'])->toBe('Common Sword')
        ->and($resource['rarity'])->toBe('common')
        ->and($resource['source'])->toBe('daily_reward')
        ->and($resource['quantity'])->toBe(1)
        ->and($resource['created_at'])->toBeString();
});
