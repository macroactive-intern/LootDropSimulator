<?php

use App\Events\LootDropped;
use App\Jobs\LootDropJob;
use App\Models\DroppedItem;
use App\Models\User;
use App\Models\UserLootStat;
use App\Services\GuildBonusService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

function createAdminUser(): User
{
    $user = User::factory()->create();
    $user->forceFill(['is_admin' => true])->save();

    return $user;
}

function configureAdminGrantLootItems(): void
{
    config()->set('loot.items', [
        [
            'name' => 'Legendary Ring',
            'weight' => 1,
            'rarity' => 'legendary',
            'stackable' => false,
            'max_stack' => 1,
        ],
    ]);
}

test('loot drop endpoint requires authentication', function (): void {
    $this->postJson('/api/loot-drop', ['source' => 'daily_reward'])
        ->assertUnauthorized();
});

test('authenticated users can queue a loot drop', function (): void {
    Queue::fake();

    $this->app->bind(GuildBonusService::class, fn () => new class extends GuildBonusService
    {
        public function getMultiplierForUser(int $userId): float
        {
            return 2.0;
        }
    });

    $user = User::factory()->create();

    $this->actingAs($user)
        ->postJson('/api/loot-drop', ['source' => 'daily_reward'])
        ->assertAccepted()
        ->assertJson([
            'success' => true,
            'message' => 'Loot drop queued.',
        ]);

    Queue::assertPushed(
        LootDropJob::class,
        fn (LootDropJob $job): bool => $job->userId === $user->id
            && $job->source === 'daily_reward'
            && $job->legendaryMultiplier === 2.0
    );
});

test('authenticated users can list paginated loot drops with rarity filter', function (): void {
    configureAdminGrantLootItems();

    $user = User::factory()->create();
    $otherUser = User::factory()->create();

    DroppedItem::query()->create([
        'user_id' => $user->id,
        'item_name' => 'Legendary Ring',
        'rarity' => 'legendary',
        'source' => 'raid',
        'quantity' => 1,
    ]);
    DroppedItem::query()->create([
        'user_id' => $user->id,
        'item_name' => 'Common Sword',
        'rarity' => 'common',
        'source' => 'daily_reward',
        'quantity' => 1,
    ]);
    DroppedItem::query()->create([
        'user_id' => $otherUser->id,
        'item_name' => 'Other Ring',
        'rarity' => 'legendary',
        'source' => 'raid',
        'quantity' => 1,
    ]);

    $this->actingAs($user)
        ->getJson('/api/loot-drops?rarity=legendary')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.item_name', 'Legendary Ring')
        ->assertJsonStructure([
            'data' => [
                [
                    'id',
                    'item_name',
                    'rarity',
                    'source',
                    'quantity',
                    'created_at',
                ],
            ],
            'links',
            'meta',
        ]);
});

test('loot drop rarity filter must match a configured rarity', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->getJson('/api/loot-drops?rarity=garbage')
        ->assertUnprocessable()
        ->assertJsonValidationErrors('rarity');
});

test('authenticated users can view their loot stats', function (): void {
    $user = User::factory()->create();

    UserLootStat::query()->create([
        'user_id' => $user->id,
        'total_drops' => 12,
        'legendary_count' => 2,
        'last_drop_at' => now(),
    ]);

    $this->actingAs($user)
        ->getJson('/api/loot-drops/stats')
        ->assertOk()
        ->assertJson([
            'user_id' => $user->id,
            'total_drops' => 12,
            'legendary_count' => 2,
            'consecutive_common_drops' => 0,
        ]);
});

test('global stats are public', function (): void {
    $user = User::factory()->create();

    DroppedItem::query()->create([
        'user_id' => $user->id,
        'item_name' => 'Legendary Ring',
        'rarity' => 'legendary',
        'source' => 'raid',
        'quantity' => 1,
    ]);
    DroppedItem::query()->create([
        'user_id' => $user->id,
        'item_name' => 'Common Sword',
        'rarity' => 'common',
        'source' => 'daily_reward',
        'quantity' => 1,
    ]);

    $this->getJson('/api/loot-drops/global-stats')
        ->assertOk()
        ->assertJson([
            'total_drops' => 2,
            'legendary_count' => 1,
        ]);
});

test('admin can manually grant configured loot', function (): void {
    Event::fake([LootDropped::class]);
    configureAdminGrantLootItems();

    $admin = createAdminUser();
    $user = User::factory()->create();

    $this->actingAs($admin)
        ->postJson('/api/admin/loot-grant', [
            'user_id' => $user->id,
            'item_name' => 'Legendary Ring',
        ])
        ->assertCreated()
        ->assertJsonPath('data.item_name', 'Legendary Ring')
        ->assertJsonPath('data.rarity', 'legendary')
        ->assertJsonPath('data.source', 'admin_grant');

    $droppedItem = DroppedItem::query()->firstOrFail();

    expect($droppedItem->user_id)->toBe($user->id)
        ->and($droppedItem->item_name)->toBe('Legendary Ring')
        ->and($droppedItem->rarity)->toBe('legendary')
        ->and($droppedItem->source)->toBe('admin_grant');

    Event::assertDispatched(
        LootDropped::class,
        fn (LootDropped $event): bool => $event->droppedItem->is($droppedItem)
    );
});

test('admin loot grant rolls back if loot dropped event handling fails', function (): void {
    configureAdminGrantLootItems();

    $admin = createAdminUser();
    $user = User::factory()->create();

    Event::listen(LootDropped::class, function (): void {
        throw new \RuntimeException('Stats listener failed.');
    });

    $this->withoutExceptionHandling();

    expect(fn () => $this->actingAs($admin)
        ->postJson('/api/admin/loot-grant', [
            'user_id' => $user->id,
            'item_name' => 'Legendary Ring',
        ]))->toThrow(\RuntimeException::class, 'Stats listener failed.');

    expect($user->droppedItems()
        ->where('item_name', 'Legendary Ring')
        ->where('source', 'admin_grant')
        ->exists())->toBeFalse();
});

test('non admin cannot manually grant loot', function (): void {
    configureAdminGrantLootItems();

    $user = User::factory()->create();
    $targetUser = User::factory()->create();

    $this->actingAs($user)
        ->postJson('/api/admin/loot-grant', [
            'user_id' => $targetUser->id,
            'item_name' => 'Legendary Ring',
        ])
        ->assertForbidden();
});

test('admin middleware returns json for plain api requests', function (): void {
    configureAdminGrantLootItems();

    $user = User::factory()->create();
    $targetUser = User::factory()->create();

    $this->actingAs($user)
        ->post('/api/admin/loot-grant', [
            'user_id' => $targetUser->id,
            'item_name' => 'Legendary Ring',
        ])
        ->assertForbidden()
        ->assertHeader('content-type', 'application/json')
        ->assertExactJson([
            'message' => 'Admin access required.',
        ]);
});

test('is admin cannot be set through mass assignment', function (): void {
    $user = User::query()->create([
        'name' => 'Not Admin',
        'email' => 'not-admin@example.com',
        'password' => 'password',
        'is_admin' => true,
    ]);

    expect($user->refresh()->is_admin)->toBeFalse();
});

test('admin loot grant requires configured item', function (): void {
    $admin = createAdminUser();
    $user = User::factory()->create();

    $this->actingAs($admin)
        ->postJson('/api/admin/loot-grant', [
            'user_id' => $user->id,
            'item_name' => 'Imaginary Wand',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('item_name');
});
