<?php

use App\Jobs\ExpireTradeJob;
use App\Models\Trade;
use App\Models\User;
use App\Observers\TradeObserver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;

uses(RefreshDatabase::class);

test('trade observer dispatches expiry job for pending trades', function (): void {
    Bus::fake();
    $expiresAt = now()->addHours(24);
    $trade = observedTrade(Trade::STATUS_PENDING, $expiresAt);

    expect($trade->isPending())->toBeTrue();

    app(TradeObserver::class)->created($trade);
    gc_collect_cycles();

    Bus::assertDispatched(ExpireTradeJob::class, function (ExpireTradeJob $job) use ($trade, $expiresAt): bool {
        return $job->tradeId === $trade->id
            && $job->delay instanceof DateTimeInterface
            && $job->delay->format('Y-m-d H:i:s') === $expiresAt->format('Y-m-d H:i:s');
    });
});

test('trade observer does not dispatch expiry job for resolved trades', function (): void {
    Bus::fake();

    $trade = observedTrade(Trade::STATUS_COMPLETED, now()->addHours(24));

    app(TradeObserver::class)->created($trade);
    gc_collect_cycles();

    Bus::assertNotDispatched(ExpireTradeJob::class);
});

function observedTrade(string $status, DateTimeInterface $expiresAt): Trade
{
    $initiator = User::factory()->create();
    $recipient = User::factory()->create();
    $guild = createTestGuild($initiator);
    attachTestGuildMember($guild, $recipient);

    return Trade::withoutEvents(fn (): Trade => Trade::query()->create([
        'initiator_id' => $initiator->id,
        'recipient_id' => $recipient->id,
        'guild_id' => $guild->id,
        'status' => $status,
        'expires_at' => $expiresAt,
    ]));
}
