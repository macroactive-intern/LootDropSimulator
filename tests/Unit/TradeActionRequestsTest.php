<?php

use App\Http\Requests\AcceptTradeRequest;
use App\Http\Requests\CancelTradeRequest;
use App\Http\Requests\RejectTradeRequest;
use App\Models\Trade;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;

uses(RefreshDatabase::class);

test('accept trade request authorizes only the recipient', function (): void {
    [$initiator, $recipient, $trade] = tradeActionRequestTrade();

    expect(tradeActionRequest(AcceptTradeRequest::class, $trade, $recipient)->authorize())->toBeTrue()
        ->and(tradeActionRequest(AcceptTradeRequest::class, $trade, $initiator)->authorize())->toBeFalse()
        ->and(tradeActionRequest(AcceptTradeRequest::class, $trade, null)->authorize())->toBeFalse();
});

test('reject trade request authorizes only the recipient', function (): void {
    [$initiator, $recipient, $trade] = tradeActionRequestTrade();

    expect(tradeActionRequest(RejectTradeRequest::class, $trade, $recipient)->authorize())->toBeTrue()
        ->and(tradeActionRequest(RejectTradeRequest::class, $trade, $initiator)->authorize())->toBeFalse()
        ->and(tradeActionRequest(RejectTradeRequest::class, $trade, null)->authorize())->toBeFalse();
});

test('cancel trade request authorizes only the initiator', function (): void {
    [$initiator, $recipient, $trade] = tradeActionRequestTrade();

    expect(tradeActionRequest(CancelTradeRequest::class, $trade, $initiator)->authorize())->toBeTrue()
        ->and(tradeActionRequest(CancelTradeRequest::class, $trade, $recipient)->authorize())->toBeFalse()
        ->and(tradeActionRequest(CancelTradeRequest::class, $trade, null)->authorize())->toBeFalse();
});

test('trade action requests fail validation when trade is not pending', function (string $requestClass, string $status): void {
    [$initiator, $recipient, $trade] = tradeActionRequestTrade($status);
    $user = $requestClass === CancelTradeRequest::class ? $initiator : $recipient;
    $request = tradeActionRequest($requestClass, $trade, $user);
    $validator = Validator::make([], $request->rules());

    foreach ($request->after() as $callback) {
        $validator->after($callback);
    }

    expect($request->authorize())->toBeTrue()
        ->and($validator->fails())->toBeTrue()
        ->and($validator->errors()->first('trade'))->toBe('Trade is no longer pending.');
})->with([
    [AcceptTradeRequest::class, Trade::STATUS_COMPLETED],
    [RejectTradeRequest::class, Trade::STATUS_REJECTED],
    [CancelTradeRequest::class, Trade::STATUS_CANCELLED],
]);

test('trade action requests pass validation while trade is pending', function (string $requestClass): void {
    [$initiator, $recipient, $trade] = tradeActionRequestTrade();
    $user = $requestClass === CancelTradeRequest::class ? $initiator : $recipient;
    $request = tradeActionRequest($requestClass, $trade, $user);
    $validator = Validator::make([], $request->rules());

    foreach ($request->after() as $callback) {
        $validator->after($callback);
    }

    expect($request->authorize())->toBeTrue()
        ->and($validator->passes())->toBeTrue();
})->with([
    AcceptTradeRequest::class,
    RejectTradeRequest::class,
    CancelTradeRequest::class,
]);

/**
 * @return array{0: User, 1: User, 2: Trade}
 */
function tradeActionRequestTrade(string $status = Trade::STATUS_PENDING): array
{
    $initiator = User::factory()->create();
    $recipient = User::factory()->create();
    $guild = createTestGuild($initiator);
    attachTestGuildMember($guild, $recipient);
    $trade = Trade::query()->create([
        'initiator_id' => $initiator->id,
        'recipient_id' => $recipient->id,
        'guild_id' => $guild->id,
        'status' => $status,
        'expires_at' => now()->addDay(),
    ]);

    return [$initiator, $recipient, $trade];
}

/**
 * @template T of \Illuminate\Foundation\Http\FormRequest
 * @param  class-string<T>  $requestClass
 * @return T
 */
function tradeActionRequest(string $requestClass, Trade $trade, ?User $user)
{
    $request = $requestClass::create('/api/trades/'.$trade->id, 'POST');
    $request->setUserResolver(fn (): ?User => $user);
    $request->setRouteResolver(fn (): object => new class($trade)
    {
        public function __construct(
            private readonly Trade $trade,
        ) {
        }

        public function parameter(string $name, mixed $default = null): mixed
        {
            return $name === 'trade' ? $this->trade : $default;
        }
    });

    return $request;
}
