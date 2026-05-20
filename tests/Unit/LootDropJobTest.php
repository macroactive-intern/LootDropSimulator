<?php

use App\Jobs\LootDropJob;
use App\Models\DroppedItem;
use App\Services\LootService;

test('it delegates loot rolls to the loot service', function (): void {
    $job = new LootDropJob(
        userId: 1,
        source: 'daily_reward',
        legendaryMultiplier: 2.0,
    );

    $lootService = \Mockery::mock(LootService::class);
    $lootService
        ->shouldReceive('roll')
        ->once()
        ->with(1, 'daily_reward', 2.0)
        ->andReturn(new DroppedItem());

    $job->handle($lootService);

    expect($job->tries)->toBe(3)
        ->and($job->backoff)->toBe([5, 30, 60]);
});
