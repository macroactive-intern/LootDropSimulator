<?php

namespace Tests\Unit;

use App\Http\Resources\DroppedItemResource;
use App\Models\DroppedItem;
use Illuminate\Http\Request;
use Tests\TestCase;

class DroppedItemResourceTest extends TestCase
{
    public function test_it_formats_a_dropped_item_for_api_responses(): void
    {
        $droppedItem = new DroppedItem([
            'item_name' => 'Common Sword',
            'rarity' => 'common',
            'source' => 'daily_reward',
            'quantity' => 1,
        ]);
        $droppedItem->id = 123;
        $droppedItem->created_at = now();

        $resource = (new DroppedItemResource($droppedItem))->toArray(new Request());

        $this->assertSame([
            'id',
            'item_name',
            'rarity',
            'source',
            'quantity',
            'created_at',
        ], array_keys($resource));

        $this->assertSame(123, $resource['id']);
        $this->assertSame('Common Sword', $resource['item_name']);
        $this->assertSame('common', $resource['rarity']);
        $this->assertSame('daily_reward', $resource['source']);
        $this->assertSame(1, $resource['quantity']);
        $this->assertIsString($resource['created_at']);
    }
}
