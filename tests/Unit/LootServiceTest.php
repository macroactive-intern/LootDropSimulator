<?php

namespace Tests\Unit;

use App\Events\LootDropped;
use App\Models\User;
use App\Models\UserLootStat;
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

    public function test_it_guarantees_rare_or_higher_after_ten_consecutive_commons(): void
    {
        Event::fake([LootDropped::class]);

        config()->set('loot.items', [
            [
                'name' => 'Common Sword',
                'weight' => 100,
                'rarity' => 'common',
                'stackable' => false,
                'max_stack' => 1,
            ],
            [
                'name' => 'Rare Shield',
                'weight' => 1,
                'rarity' => 'rare',
                'stackable' => false,
                'max_stack' => 1,
            ],
        ]);

        $user = User::factory()->create();
        UserLootStat::query()->create([
            'user_id' => $user->id,
            'total_drops' => 10,
            'legendary_count' => 0,
            'consecutive_common_drops' => 10,
        ]);

        $droppedItem = app(LootService::class)->roll(
            userId: $user->id,
            source: 'pity_test',
        );

        $this->assertSame('Rare Shield', $droppedItem->item_name);
        $this->assertSame('rare', $droppedItem->rarity);
    }
}
