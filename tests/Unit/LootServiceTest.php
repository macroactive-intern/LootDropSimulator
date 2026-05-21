<?php

use App\Events\LootDropped;
use App\Models\User;
use App\Models\UserLootStat;
use App\Services\LootTable;
use App\Services\LootService;
use Illuminate\Contracts\Events\Dispatcher;
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

test('it fails clearly when pity filtering leaves no rollable loot', function (): void {
    Event::fake([LootDropped::class]);

    config()->set('loot.items', [
        [
            'name' => 'Common Sword',
            'weight' => 100,
            'rarity' => 'common',
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

    expect(fn () => app(LootService::class)->roll(
        userId: $user->id,
        source: 'misconfigured_pity_test',
    ))->toThrow(UnexpectedValueException::class, 'Loot table has no rollable items.');

    $this->assertDatabaseMissing('dropped_items', [
        'user_id' => $user->id,
        'source' => 'misconfigured_pity_test',
    ]);
});

test('it clamps invalid stack max to at least one', function (): void {
    Event::fake([LootDropped::class]);

    config()->set('loot.items', [
        [
            'name' => 'Broken Stack',
            'weight' => 1,
            'rarity' => 'common',
            'stackable' => true,
            'max_stack' => 0,
        ],
    ]);

    $user = User::factory()->create();

    $droppedItem = app(LootService::class)->roll(
        userId: $user->id,
        source: 'broken_config_test',
    );

    expect($droppedItem->quantity)->toBe(1);
});

test('it rolls back the dropped item if event dispatch fails', function (): void {
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

    $failingDispatcher = new class implements Dispatcher
    {
        public function listen($events, $listener = null): void
        {
        }

        public function hasListeners($eventName): bool
        {
            return true;
        }

        public function subscribe($subscriber): void
        {
        }

        public function until($event, $payload = []): mixed
        {
            throw new RuntimeException('Listener failed.');
        }

        public function dispatch($event, $payload = [], $halt = false): mixed
        {
            throw new RuntimeException('Listener failed.');
        }

        public function push($event, $payload = []): void
        {
        }

        public function flush($event): void
        {
        }

        public function forget($event): void
        {
        }

        public function forgetPushed(): void
        {
        }
    };

    $service = new LootService(app(LootTable::class), $failingDispatcher);

    expect(fn () => $service->roll($user->id, 'test_chest'))
        ->toThrow(RuntimeException::class, 'Listener failed.');

    $this->assertDatabaseMissing('dropped_items', [
        'user_id' => $user->id,
        'item_name' => 'Guaranteed Sword',
    ]);
});
