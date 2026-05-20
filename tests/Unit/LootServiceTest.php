<?php

use App\Events\LootDropped;
use App\Models\User;
use App\Models\UserLootStat;
use App\Services\LootService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

test('it rolls saves dispatches and returns a dropped item', function (): void {
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

    expect($droppedItem->user_id)->toBe($user->id)
        ->and($droppedItem->item_name)->toBe('Guaranteed Sword')
        ->and($droppedItem->rarity)->toBe('common')
        ->and($droppedItem->source)->toBe('test_chest')
        ->and($droppedItem->quantity)->toBe(1);

    $this->assertDatabaseHas('dropped_items', [
        'id' => $droppedItem->id,
        'user_id' => $user->id,
        'item_name' => 'Guaranteed Sword',
    ]);

    Event::assertDispatched(
        LootDropped::class,
        fn (LootDropped $event): bool => $event->droppedItem->is($droppedItem)
    );
});

test('it guarantees rare or higher after ten consecutive commons', function (): void {
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

    expect($droppedItem->item_name)->toBe('Rare Shield')
        ->and($droppedItem->rarity)->toBe('rare');
});
