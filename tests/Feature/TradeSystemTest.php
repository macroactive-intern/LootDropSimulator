<?php

use App\Jobs\ExpireTradeJob;
use App\Models\EscrowItem;
use App\Models\Guild;
use App\Models\InventoryItem;
use App\Models\Item;
use App\Models\Trade;
use App\Models\User;
use App\Services\TradeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Bus::fake();
});

test('trade proposal fails if initiator and recipient share no guild', function (): void {
    [$initiator, $recipient, $guild] = l8TradeParticipants(shareGuild: false);
    $offeredInventoryItem = l8InventoryItemFor($initiator, 'No Guild Sword', 100);
    $requestedInventoryItem = l8InventoryItemFor($recipient, 'No Guild Shield', 100);

    $this->actingAs($initiator)
        ->postJson('/api/trades', l8TradePayload($recipient, $guild, $offeredInventoryItem, $requestedInventoryItem))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('guild_id');
});

test('trade proposal succeeds if both users share the supplied guild', function (): void {
    [$initiator, $recipient, $guild] = l8TradeParticipants();
    $offeredInventoryItem = l8InventoryItemFor($initiator, 'Shared Guild Sword', 100);
    $requestedInventoryItem = l8InventoryItemFor($recipient, 'Shared Guild Shield', 100);

    $this->actingAs($initiator)
        ->postJson('/api/trades', l8TradePayload($recipient, $guild, $offeredInventoryItem, $requestedInventoryItem))
        ->assertCreated()
        ->assertJsonPath('data.initiator_id', $initiator->id)
        ->assertJsonPath('data.recipient_id', $recipient->id)
        ->assertJsonPath('data.guild_id', $guild->id)
        ->assertJsonPath('data.status', Trade::STATUS_PENDING)
        ->assertJsonPath('data.status_changed_at', fn (?string $value): bool => $value !== null);
});

test('trade proposal fails when a user has too many pending trades', function (): void {
    [$initiator, $recipient, $guild] = l8TradeParticipants();
    $offeredInventoryItem = l8InventoryItemFor($initiator, 'Pending Limit Sword', 100);
    $requestedInventoryItem = l8InventoryItemFor($recipient, 'Pending Limit Shield', 100);

    foreach (range(1, 10) as $index) {
        Trade::query()->create([
            'initiator_id' => $initiator->id,
            'recipient_id' => User::factory()->create()->id,
            'guild_id' => $guild->id,
            'status' => Trade::STATUS_PENDING,
            'expires_at' => now()->addHours(24),
        ]);
    }

    $this->actingAs($initiator)
        ->postJson('/api/trades', l8TradePayload($recipient, $guild, $offeredInventoryItem, $requestedInventoryItem))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('pending_trades')
        ->assertSee('A user cannot have more than 10 pending trades.');
});

test('trade index rejects accepted status filter', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->getJson('/api/trades?status=accepted')
        ->assertUnprocessable()
        ->assertJsonValidationErrors('status');
});

test('offered items move to escrow on proposal', function (): void {
    [$initiator, $recipient, $guild] = l8TradeParticipants();
    $offeredInventoryItem = l8InventoryItemFor($initiator, 'Escrow Sword', 100);
    $requestedInventoryItem = l8InventoryItemFor($recipient, 'Escrow Shield', 100);

    $tradeId = $this->actingAs($initiator)
        ->postJson('/api/trades', l8TradePayload($recipient, $guild, $offeredInventoryItem, $requestedInventoryItem))
        ->assertCreated()
        ->json('data.id');

    expect($offeredInventoryItem->refresh()->is_in_escrow)->toBeTrue()
        ->and(Trade::query()->findOrFail($tradeId)->status)->toBe(Trade::STATUS_PENDING)
        ->and(EscrowItem::query()
            ->where('trade_id', $tradeId)
            ->where('inventory_item_id', $offeredInventoryItem->id)
            ->where('quantity', 1)
            ->exists())->toBeTrue();
});

test('fair trade check blocks proposals where value difference is greater than twenty five percent', function (): void {
    [$initiator, $recipient, $guild] = l8TradeParticipants();
    $offeredInventoryItem = l8InventoryItemFor($initiator, 'Low Value Sword', 100);
    $requestedInventoryItem = l8InventoryItemFor($recipient, 'High Value Shield', 200);

    $this->actingAs($initiator)
        ->postJson('/api/trades', l8TradePayload($recipient, $guild, $offeredInventoryItem, $requestedInventoryItem))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('trade_value')
        ->assertSee('Offered total: 100')
        ->assertSee('Requested total: 200');
});

test('unique items enforce quantity one', function (): void {
    [$initiator, $recipient, $guild] = l8TradeParticipants();
    $offeredInventoryItem = l8InventoryItemFor(
        user: $initiator,
        name: 'Stacked Unique Relic',
        baseValue: 100,
        quantity: 2,
        isUnique: true
    );
    $requestedInventoryItem = l8InventoryItemFor($recipient, 'Unique Test Shield', 100);

    $this->actingAs($initiator)
        ->postJson('/api/trades', l8TradePayload(
            recipient: $recipient,
            guild: $guild,
            offeredInventoryItem: $offeredInventoryItem,
            requestedInventoryItem: $requestedInventoryItem,
            offeredQuantity: 2
        ))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('offered_items.0.quantity')
        ->assertSee('Unique items must have a quantity of 1.');
});

test('accepting transfers items atomically', function (): void {
    [$initiator, $recipient, $guild] = l8TradeParticipants();
    $offeredInventoryItem = l8InventoryItemFor($initiator, 'Accepted Sword', 100);
    $requestedInventoryItem = l8InventoryItemFor($recipient, 'Accepted Shield', 100);
    $trade = l8ProposeTradeThroughApi($this, $initiator, $recipient, $guild, $offeredInventoryItem, $requestedInventoryItem);

    $this->actingAs($recipient)
        ->postJson("/api/trades/{$trade->id}/accept")
        ->assertOk()
        ->assertJsonPath('data.status', Trade::STATUS_COMPLETED);

    expect($offeredInventoryItem->refresh()->user_id)->toBe($recipient->id)
        ->and($offeredInventoryItem->is_in_escrow)->toBeFalse()
        ->and($requestedInventoryItem->refresh()->user_id)->toBe($initiator->id)
        ->and($requestedInventoryItem->is_in_escrow)->toBeFalse()
        ->and($trade->refresh()->status)->toBe(Trade::STATUS_COMPLETED)
        ->and($trade->escrowItems()->count())->toBe(0);
});

test('rejecting releases escrow', function (): void {
    [$initiator, $recipient, $guild] = l8TradeParticipants();
    $offeredInventoryItem = l8InventoryItemFor($initiator, 'Rejected Sword', 100);
    $requestedInventoryItem = l8InventoryItemFor($recipient, 'Rejected Shield', 100);
    $trade = l8ProposeTradeThroughApi($this, $initiator, $recipient, $guild, $offeredInventoryItem, $requestedInventoryItem);

    $this->actingAs($recipient)
        ->postJson("/api/trades/{$trade->id}/reject")
        ->assertOk()
        ->assertJsonPath('data.status', Trade::STATUS_REJECTED);

    expect($offeredInventoryItem->refresh()->user_id)->toBe($initiator->id)
        ->and($offeredInventoryItem->is_in_escrow)->toBeFalse()
        ->and($trade->refresh()->status)->toBe(Trade::STATUS_REJECTED)
        ->and($trade->escrowItems()->count())->toBe(0);
});

test('cancelling releases escrow', function (): void {
    [$initiator, $recipient, $guild] = l8TradeParticipants();
    $offeredInventoryItem = l8InventoryItemFor($initiator, 'Cancelled Sword', 100);
    $requestedInventoryItem = l8InventoryItemFor($recipient, 'Cancelled Shield', 100);
    $trade = l8ProposeTradeThroughApi($this, $initiator, $recipient, $guild, $offeredInventoryItem, $requestedInventoryItem);

    $this->actingAs($recipient)
        ->postJson("/api/trades/{$trade->id}/cancel")
        ->assertForbidden();

    $this->actingAs($initiator)
        ->postJson("/api/trades/{$trade->id}/cancel")
        ->assertOk()
        ->assertJsonPath('data.status', Trade::STATUS_CANCELLED);

    expect($offeredInventoryItem->refresh()->user_id)->toBe($initiator->id)
        ->and($offeredInventoryItem->is_in_escrow)->toBeFalse()
        ->and($trade->refresh()->status)->toBe(Trade::STATUS_CANCELLED)
        ->and($trade->escrowItems()->count())->toBe(0);
});

test('expire trade job expires pending trade', function (): void {
    [$initiator, $recipient, $guild] = l8TradeParticipants();
    $offeredInventoryItem = l8InventoryItemFor($initiator, 'Expired Job Sword', 100);
    $requestedInventoryItem = l8InventoryItemFor($recipient, 'Expired Job Shield', 100);
    $trade = l8ProposeTradeThroughApi($this, $initiator, $recipient, $guild, $offeredInventoryItem, $requestedInventoryItem);

    (new ExpireTradeJob($trade->id))->handle(app(TradeService::class));

    expect($trade->refresh()->status)->toBe(Trade::STATUS_EXPIRED)
        ->and($offeredInventoryItem->refresh()->user_id)->toBe($initiator->id)
        ->and($offeredInventoryItem->is_in_escrow)->toBeFalse()
        ->and($trade->escrowItems()->count())->toBe(0);
});

test('already completed trade is not expired by expire trade job', function (): void {
    [$initiator, $recipient, $guild] = l8TradeParticipants();
    $offeredInventoryItem = l8InventoryItemFor($initiator, 'Completed Job Sword', 100);
    $requestedInventoryItem = l8InventoryItemFor($recipient, 'Completed Job Shield', 100);
    $trade = l8ProposeTradeThroughApi($this, $initiator, $recipient, $guild, $offeredInventoryItem, $requestedInventoryItem);

    $this->actingAs($recipient)
        ->postJson("/api/trades/{$trade->id}/accept")
        ->assertOk();

    (new ExpireTradeJob($trade->id))->handle(app(TradeService::class));

    expect($trade->refresh()->status)->toBe(Trade::STATUS_COMPLETED)
        ->and($trade->escrowItems()->count())->toBe(0)
        ->and($offeredInventoryItem->refresh()->user_id)->toBe($recipient->id)
        ->and($requestedInventoryItem->refresh()->user_id)->toBe($initiator->id);
});

/**
 * @return array{0: User, 1: User, 2: Guild}
 */
function l8TradeParticipants(bool $shareGuild = true): array
{
    $initiator = User::factory()->create();
    $recipient = User::factory()->create();
    $guild = createTestGuild($initiator);

    if ($shareGuild) {
        attachTestGuildMember($guild, $recipient);
    }

    return [$initiator, $recipient, $guild];
}

function l8InventoryItemFor(
    User $user,
    string $name,
    int $baseValue,
    int $quantity = 1,
    bool $isUnique = false
): InventoryItem {
    $item = Item::query()->create([
        'name' => $name,
        'base_value' => $baseValue,
        'is_unique' => $isUnique,
    ]);

    return InventoryItem::query()->create([
        'user_id' => $user->id,
        'item_id' => $item->id,
        'quantity' => $quantity,
        'is_tradable' => true,
        'is_in_escrow' => false,
    ]);
}

/**
 * @return array<string, mixed>
 */
function l8TradePayload(
    User $recipient,
    Guild $guild,
    InventoryItem $offeredInventoryItem,
    InventoryItem $requestedInventoryItem,
    int $offeredQuantity = 1,
    int $requestedQuantity = 1
): array {
    return [
        'recipient_id' => $recipient->id,
        'guild_id' => $guild->id,
        'offered_items' => [
            [
                'inventory_item_id' => $offeredInventoryItem->id,
                'quantity' => $offeredQuantity,
            ],
        ],
        'requested_items' => [
            [
                'inventory_item_id' => $requestedInventoryItem->id,
                'quantity' => $requestedQuantity,
            ],
        ],
    ];
}

function l8ProposeTradeThroughApi(
    mixed $testCase,
    User $initiator,
    User $recipient,
    Guild $guild,
    InventoryItem $offeredInventoryItem,
    InventoryItem $requestedInventoryItem
): Trade {
    $tradeId = $testCase->actingAs($initiator)
        ->postJson('/api/trades', l8TradePayload($recipient, $guild, $offeredInventoryItem, $requestedInventoryItem))
        ->assertCreated()
        ->json('data.id');

    return Trade::query()->findOrFail($tradeId);
}
