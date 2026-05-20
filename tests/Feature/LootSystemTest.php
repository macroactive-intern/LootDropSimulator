<?php

use App\Events\LootDropped;
use App\Jobs\LootDropJob;
use App\Models\DroppedItem;
use App\Models\User;
use App\Services\LootService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;

uses(RefreshDatabase::class);

function createLootSystemDroppedItem(
    User $user,
    string $itemName = 'Common Sword',
    string $rarity = 'common',
): DroppedItem {
    return DroppedItem::query()->create([
        'user_id' => $user->id,
        'item_name' => $itemName,
        'rarity' => $rarity,
        'source' => 'test_source',
        'quantity' => 1,
    ]);
}

test('job creates dropped item and fires loot dropped event', function (): void {
    Event::fake([LootDropped::class]);

    config()->set('loot.items', [
        [
            'name' => 'Guaranteed Ring',
            'weight' => 1,
            'rarity' => 'legendary',
            'stackable' => false,
            'max_stack' => 1,
        ],
    ]);

    $user = User::factory()->create();

    (new LootDropJob($user->id, 'boss_fight', 2.0))->handle(app(LootService::class));

    $droppedItem = DroppedItem::query()->firstOrFail();

    expect($droppedItem->user_id)->toBe($user->id)
        ->and($droppedItem->item_name)->toBe('Guaranteed Ring')
        ->and($droppedItem->rarity)->toBe('legendary')
        ->and($droppedItem->source)->toBe('boss_fight');

    Event::assertDispatched(
        LootDropped::class,
        fn (LootDropped $event): bool => $event->droppedItem->is($droppedItem)
    );
});

test('loot dropped listeners execute and update user stats', function (): void {
    Log::spy();

    $user = User::factory()->create();
    $droppedItem = createLootSystemDroppedItem($user, rarity: 'legendary');

    event(new LootDropped($droppedItem));

    $this->assertDatabaseHas('user_loot_stats', [
        'user_id' => $user->id,
        'total_drops' => 1,
        'legendary_count' => 1,
        'consecutive_common_drops' => 0,
    ]);

    $this->assertNotNull($user->lootStat()->first()?->last_drop_at);

    Log::shouldHaveReceived('info')
        ->once()
        ->with('Loot dropped', \Mockery::on(
            fn (array $context): bool => $context['dropped_item_id'] === $droppedItem->id
                && $context['user_id'] === $user->id
                && $context['rarity'] === 'legendary'
        ));
});

test('common streak increments and resets on rare or higher drop', function (): void {
    $user = User::factory()->create();

    event(new LootDropped(createLootSystemDroppedItem($user, rarity: 'common')));
    event(new LootDropped(createLootSystemDroppedItem($user, rarity: 'common')));

    $this->assertDatabaseHas('user_loot_stats', [
        'user_id' => $user->id,
        'total_drops' => 2,
        'consecutive_common_drops' => 2,
    ]);

    event(new LootDropped(createLootSystemDroppedItem($user, itemName: 'Rare Shield', rarity: 'rare')));

    $this->assertDatabaseHas('user_loot_stats', [
        'user_id' => $user->id,
        'total_drops' => 3,
        'consecutive_common_drops' => 0,
    ]);
});

test('loot drop pagination works', function (): void {
    $user = User::factory()->create();

    for ($drop = 0; $drop < 16; $drop++) {
        createLootSystemDroppedItem($user, itemName: 'Common Sword '.$drop);
    }

    $this->actingAs($user)
        ->getJson('/api/loot-drops')
        ->assertOk()
        ->assertJsonCount(15, 'data')
        ->assertJsonPath('meta.per_page', 15)
        ->assertJsonPath('meta.total', 16);
});

test('loot drop rarity filtering works', function (): void {
    $user = User::factory()->create();

    createLootSystemDroppedItem($user, itemName: 'Common Sword', rarity: 'common');
    createLootSystemDroppedItem($user, itemName: 'Legendary Ring', rarity: 'legendary');

    $this->actingAs($user)
        ->getJson('/api/loot-drops?rarity=legendary')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.item_name', 'Legendary Ring')
        ->assertJsonPath('data.0.rarity', 'legendary');
});

test('global stats endpoint works', function (): void {
    $user = User::factory()->create();

    createLootSystemDroppedItem($user, rarity: 'common');
    createLootSystemDroppedItem($user, rarity: 'legendary');
    createLootSystemDroppedItem($user, rarity: 'legendary');

    $this->getJson('/api/loot-drops/global-stats')
        ->assertOk()
        ->assertJson([
            'total_drops' => 3,
            'legendary_count' => 2,
        ]);
});
