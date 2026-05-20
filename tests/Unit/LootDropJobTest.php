<?php

namespace Tests\Unit;

use App\Jobs\LootDropJob;
use App\Models\DroppedItem;
use App\Services\LootService;
use Mockery;
use Tests\TestCase;

class LootDropJobTest extends TestCase
{
    public function test_it_delegates_loot_rolls_to_the_loot_service(): void
    {
        $job = new LootDropJob(
            userId: 1,
            source: 'daily_reward',
            legendaryMultiplier: 2.0,
        );

        $lootService = Mockery::mock(LootService::class);
        $lootService
            ->shouldReceive('roll')
            ->once()
            ->with(1, 'daily_reward', 2.0)
            ->andReturn(new DroppedItem());

        $job->handle($lootService);

        $this->assertSame(3, $job->tries);
        $this->assertSame([5, 30, 60], $job->backoff);
    }
}
