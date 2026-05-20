<?php

namespace Tests\Feature;

use App\Events\LootDropped;
use App\Jobs\LootDropJob;
use App\Models\DroppedItem;
use App\Models\User;
use App\Services\GuildBonusService;
use App\Services\LootService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

class LootSystemTest extends TestCase
{
    use RefreshDatabase;

    public function test_loot_drop_endpoint_queues_a_job(): void
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
            ->postJson('/api/loot-drop', ['source' => 'boss_fight'])
            ->assertAccepted()
            ->assertJson([
                'success' => true,
                'message' => 'Loot drop queued.',
            ]);

        Queue::assertPushed(
            LootDropJob::class,
            fn (LootDropJob $job): bool => $job->userId === $user->id
                && $job->source === 'boss_fight'
                && $job->legendaryMultiplier === 2.0
        );
    }

    public function test_job_creates_dropped_item_and_fires_loot_dropped_event(): void
    {
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

        $this->assertSame($user->id, $droppedItem->user_id);
        $this->assertSame('Guaranteed Ring', $droppedItem->item_name);
        $this->assertSame('legendary', $droppedItem->rarity);
        $this->assertSame('boss_fight', $droppedItem->source);

        Event::assertDispatched(
            LootDropped::class,
            fn (LootDropped $event): bool => $event->droppedItem->is($droppedItem)
        );
    }

    public function test_loot_dropped_listeners_execute_and_update_user_stats(): void
    {
        Log::spy();

        $user = User::factory()->create();
        $droppedItem = $this->createDroppedItem($user, rarity: 'legendary');

        event(new LootDropped($droppedItem));

        $this->assertDatabaseHas('user_loot_stats', [
            'user_id' => $user->id,
            'total_drops' => 1,
            'legendary_count' => 1,
        ]);

        $this->assertNotNull($user->lootStat()->first()?->last_drop_at);

        Log::shouldHaveReceived('info')
            ->once()
            ->with('Loot dropped', Mockery::on(
                fn (array $context): bool => $context['dropped_item_id'] === $droppedItem->id
                    && $context['user_id'] === $user->id
                    && $context['rarity'] === 'legendary'
            ));
    }

    public function test_loot_drop_pagination_works(): void
    {
        $user = User::factory()->create();

        for ($drop = 0; $drop < 16; $drop++) {
            $this->createDroppedItem($user, itemName: 'Common Sword '.$drop);
        }

        $this->actingAs($user)
            ->getJson('/api/loot-drops')
            ->assertOk()
            ->assertJsonCount(15, 'data')
            ->assertJsonPath('meta.per_page', 15)
            ->assertJsonPath('meta.total', 16);
    }

    public function test_loot_drop_rarity_filtering_works(): void
    {
        $user = User::factory()->create();

        $this->createDroppedItem($user, itemName: 'Common Sword', rarity: 'common');
        $this->createDroppedItem($user, itemName: 'Legendary Ring', rarity: 'legendary');

        $this->actingAs($user)
            ->getJson('/api/loot-drops?rarity=legendary')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.item_name', 'Legendary Ring')
            ->assertJsonPath('data.0.rarity', 'legendary');
    }

    public function test_global_stats_endpoint_works(): void
    {
        $user = User::factory()->create();

        $this->createDroppedItem($user, rarity: 'common');
        $this->createDroppedItem($user, rarity: 'legendary');
        $this->createDroppedItem($user, rarity: 'legendary');

        $this->getJson('/api/loot-drops/global-stats')
            ->assertOk()
            ->assertJson([
                'total_drops' => 3,
                'legendary_count' => 2,
            ]);
    }

    private function createDroppedItem(
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
}
