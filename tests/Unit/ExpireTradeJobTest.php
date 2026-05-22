<?php

use App\Jobs\ExpireTradeJob;
use App\Models\Trade;
use App\Models\User;
use App\Services\TradeService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Bus::fake();
});

test('expire trade job stores a trade id when constructed with a trade model', function (): void {
    $trade = expirableTrade();
    $job = new ExpireTradeJob($trade);

    expect($job)->toBeInstanceOf(ShouldQueue::class)
        ->and($job->tradeId)->toBe($trade->id);
});

test('expire trade job stores a trade id when constructed with an id', function (): void {
    $job = new ExpireTradeJob(123);

    expect($job->tradeId)->toBe(123);
});

test('expire trade job delegates pending trades to the trade service', function (): void {
    $trade = expirableTrade();
    $job = new ExpireTradeJob($trade->id);
    $tradeService = \Mockery::mock(TradeService::class);

    $tradeService
        ->shouldReceive('expireIfPending')
        ->once()
        ->with(\Mockery::on(fn (Trade $loadedTrade): bool => $loadedTrade->is($trade)));

    $job->handle($tradeService);
});

test('expire trade job does nothing when trade no longer exists', function (): void {
    $job = new ExpireTradeJob(999);
    $tradeService = \Mockery::mock(TradeService::class);

    $tradeService->shouldNotReceive('expireIfPending');

    $job->handle($tradeService);
});

test('expire trade job does nothing when trade is already resolved', function (): void {
    $trade = expirableTrade(Trade::STATUS_COMPLETED);
    $job = new ExpireTradeJob($trade->id);
    $tradeService = \Mockery::mock(TradeService::class);

    $tradeService->shouldNotReceive('expireIfPending');

    $job->handle($tradeService);
});

function expirableTrade(string $status = Trade::STATUS_PENDING): Trade
{
    $initiator = User::factory()->create();
    $recipient = User::factory()->create();
    $guild = createTestGuild($initiator);
    attachTestGuildMember($guild, $recipient);

    return Trade::query()->create([
        'initiator_id' => $initiator->id,
        'recipient_id' => $recipient->id,
        'guild_id' => $guild->id,
        'status' => $status,
        'expires_at' => now()->addDay(),
    ]);
}
