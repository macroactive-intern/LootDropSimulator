<?php

use App\Jobs\LootDropJob;
use App\Models\DroppedItem;
use App\Services\LootService;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Log;
use Psr\Log\LoggerInterface;

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

test('it prevents overlapping loot drops per user', function (): void {
    $job = new LootDropJob(
        userId: 42,
        source: 'daily_reward',
    );

    $middleware = $job->middleware();

    expect($middleware)->toHaveCount(1)
        ->and($middleware[0])->toBeInstanceOf(WithoutOverlapping::class)
        ->and($middleware[0]->key)->toBe('loot-drop-user:42')
        ->and($middleware[0]->releaseAfter)->toBe(5)
        ->and($middleware[0]->expiresAfter)->toBe(300);
});

test('it logs failed jobs to the loot errors channel with the exception', function (): void {
    $job = new LootDropJob(
        userId: 7,
        source: 'boss_fight',
        legendaryMultiplier: 2.0,
    );
    $exception = new \RuntimeException('Loot service exploded.');
    $logger = \Mockery::mock(LoggerInterface::class);

    Log::shouldReceive('channel')
        ->once()
        ->with('loot-errors')
        ->andReturn($logger);

    $logger->shouldReceive('error')
        ->once()
        ->with('Loot drop job failed', \Mockery::on(
            fn (array $context): bool => $context['user_id'] === 7
                && $context['source'] === 'boss_fight'
                && $context['legendary_multiplier'] === 2.0
                && $context['exception'] === $exception
        ));

    $job->failed($exception);
});
