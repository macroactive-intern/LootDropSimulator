<?php

namespace Tests\Unit;

use App\Events\LootDropped;
use App\Models\User;
use App\Services\LootService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class LootServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_rolls_saves_dispatches_and_returns_a_dropped_item(): void
    {
        Event::fake([LootDropped::class]);

        config()->set('loot.items', [
            [
                'name' => 'Guaranteed Sword',
                'weight' => 1,
                'rarity' => 'common',
                'stackable' => false,
                'max_stack' => 1,
            ],
        ]);

        $user = User::factory()->create();

        $droppedItem = app(LootService::class)->roll(
            userId: $user->id,
            source: 'test_chest',
        );

        $this->assertSame($user->id, $droppedItem->user_id);
        $this->assertSame('Guaranteed Sword', $droppedItem->item_name);
        $this->assertSame('common', $droppedItem->rarity);
        $this->assertSame('test_chest', $droppedItem->source);
        $this->assertSame(1, $droppedItem->quantity);

        $this->assertDatabaseHas('dropped_items', [
            'id' => $droppedItem->id,
            'user_id' => $user->id,
            'item_name' => 'Guaranteed Sword',
        ]);

        Event::assertDispatched(
            LootDropped::class,
            fn (LootDropped $event): bool => $event->droppedItem->is($droppedItem)
        );
    }
}
