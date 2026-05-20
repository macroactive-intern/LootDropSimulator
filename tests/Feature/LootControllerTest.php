<?php

namespace Tests\Feature;

use App\Jobs\LootDropJob;
use App\Models\DroppedItem;
use App\Models\User;
use App\Models\UserLootStat;
use App\Services\GuildBonusService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class LootControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_loot_drop_endpoint_requires_authentication(): void
    {
        $this->postJson('/api/loot-drop', ['source' => 'daily_reward'])
            ->assertUnauthorized();
    }

    public function test_authenticated_users_can_queue_a_loot_drop(): void
    {
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
    }

    public function test_authenticated_users_can_list_paginated_loot_drops_with_rarity_filter(): void
    {
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
    }

    public function test_authenticated_users_can_view_their_loot_stats(): void
    {
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
    }

    public function test_global_stats_are_public(): void
    {
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
    }
}
