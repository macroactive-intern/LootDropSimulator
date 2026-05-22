<?php

use App\Models\InventoryItem;
use App\Models\Item;
use App\Models\Trade;
use App\Models\User;
use App\Services\TradeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Bus::fake();
});

test('accept transfers full stacks and completes the trade', function (): void {
    [$initiator, $recipient, $guild] = tradeParticipants();
    $offeredInventoryItem = inventoryItemFor($initiator, 'Crystal Sword', 100, 1);
    $requestedInventoryItem = inventoryItemFor($recipient, 'Moon Shield', 100, 1);
    $service = app(TradeService::class);
    $trade = $service->propose($initiator, [
        'recipient_id' => $recipient->id,
        'guild_id' => $guild->id,
        'offered_items' => [
            ['inventory_item_id' => $offeredInventoryItem->id, 'quantity' => 1],
        ],
        'requested_items' => [
            ['inventory_item_id' => $requestedInventoryItem->id, 'quantity' => 1],
        ],
    ]);

    $acceptedTrade = $service->accept($trade, $recipient);

    expect($acceptedTrade->status)->toBe(Trade::STATUS_COMPLETED)
        ->and($offeredInventoryItem->refresh()->user_id)->toBe($recipient->id)
        ->and($offeredInventoryItem->is_in_escrow)->toBeFalse()
        ->and($requestedInventoryItem->refresh()->user_id)->toBe($initiator->id)
        ->and($requestedInventoryItem->is_in_escrow)->toBeFalse()
        ->and($trade->escrowItems()->count())->toBe(0);
});

test('accept splits partial stacks without losing quantity', function (): void {
    [$initiator, $recipient, $guild] = tradeParticipants();
    $offeredInventoryItem = inventoryItemFor($initiator, 'Potion Bundle', 10, 5);
    $requestedInventoryItem = inventoryItemFor($recipient, 'Gem Stack', 10, 7);
    $service = app(TradeService::class);
    $trade = $service->propose($initiator, [
        'recipient_id' => $recipient->id,
        'guild_id' => $guild->id,
        'offered_items' => [
            ['inventory_item_id' => $offeredInventoryItem->id, 'quantity' => 2],
        ],
        'requested_items' => [
            ['inventory_item_id' => $requestedInventoryItem->id, 'quantity' => 3],
        ],
    ]);

    $service->accept($trade, $recipient);

    $offeredInventoryItem->refresh();
    $requestedInventoryItem->refresh();
    $recipientReceivedStack = InventoryItem::query()
        ->where('user_id', $recipient->id)
        ->where('item_id', $offeredInventoryItem->item_id)
        ->where('id', '!=', $offeredInventoryItem->id)
        ->firstOrFail();
    $initiatorReceivedStack = InventoryItem::query()
        ->where('user_id', $initiator->id)
        ->where('item_id', $requestedInventoryItem->item_id)
        ->where('id', '!=', $requestedInventoryItem->id)
        ->firstOrFail();

    expect($offeredInventoryItem->user_id)->toBe($initiator->id)
        ->and($offeredInventoryItem->quantity)->toBe(3)
        ->and($offeredInventoryItem->is_in_escrow)->toBeFalse()
        ->and($recipientReceivedStack->quantity)->toBe(2)
        ->and($recipientReceivedStack->is_in_escrow)->toBeFalse()
        ->and($requestedInventoryItem->user_id)->toBe($recipient->id)
        ->and($requestedInventoryItem->quantity)->toBe(4)
        ->and($requestedInventoryItem->is_in_escrow)->toBeFalse()
        ->and($initiatorReceivedStack->quantity)->toBe(3)
        ->and($initiatorReceivedStack->is_in_escrow)->toBeFalse()
        ->and(InventoryItem::query()->where('item_id', $offeredInventoryItem->item_id)->sum('quantity'))->toBe(5)
        ->and(InventoryItem::query()->where('item_id', $requestedInventoryItem->item_id)->sum('quantity'))->toBe(7)
        ->and($trade->refresh()->status)->toBe(Trade::STATUS_COMPLETED)
        ->and($trade->escrowItems()->count())->toBe(0);
});

test('repeated accept attempts only complete the trade once', function (): void {
    [$initiator, $recipient, $guild] = tradeParticipants();
    $offeredInventoryItem = inventoryItemFor($initiator, 'Ancient Coin', 100, 1);
    $requestedInventoryItem = inventoryItemFor($recipient, 'Silver Coin', 100, 1);
    $service = app(TradeService::class);
    $trade = $service->propose($initiator, [
        'recipient_id' => $recipient->id,
        'guild_id' => $guild->id,
        'offered_items' => [
            ['inventory_item_id' => $offeredInventoryItem->id, 'quantity' => 1],
        ],
        'requested_items' => [
            ['inventory_item_id' => $requestedInventoryItem->id, 'quantity' => 1],
        ],
    ]);
    $completedAttempts = 0;
    $rejectedAttempts = 0;

    for ($attempt = 1; $attempt <= 10; $attempt++) {
        try {
            $service->accept($trade, $recipient);
            $completedAttempts++;
        } catch (ValidationException) {
            $rejectedAttempts++;
        }
    }

    expect($completedAttempts)->toBe(1)
        ->and($rejectedAttempts)->toBe(9)
        ->and($trade->refresh()->status)->toBe(Trade::STATUS_COMPLETED)
        ->and($trade->escrowItems()->count())->toBe(0)
        ->and(InventoryItem::query()->where('item_id', $offeredInventoryItem->item_id)->sum('quantity'))->toBe(1)
        ->and(InventoryItem::query()->where('item_id', $requestedInventoryItem->item_id)->sum('quantity'))->toBe(1);
});

test('trade status changed timestamp is recorded on create and status changes', function (): void {
    [$initiator, $recipient, $guild] = tradeParticipants();
    $offeredInventoryItem = inventoryItemFor($initiator, 'Timestamp Sword', 100, 1);
    $requestedInventoryItem = inventoryItemFor($recipient, 'Timestamp Shield', 100, 1);
    $service = app(TradeService::class);
    $createdAt = now()->startOfSecond();
    $changedAt = $createdAt->copy()->addMinute();

    $this->travelTo($createdAt);

    $trade = proposedTrade($service, $initiator, $recipient, $guild, $offeredInventoryItem, $requestedInventoryItem);

    expect($trade->status_changed_at?->format('Y-m-d H:i:s'))->toBe($createdAt->format('Y-m-d H:i:s'));

    $this->travelTo($changedAt);

    $acceptedTrade = $service->accept($trade, $recipient);

    expect($acceptedTrade->status_changed_at?->format('Y-m-d H:i:s'))->toBe($changedAt->format('Y-m-d H:i:s'));

    $this->travelBack();
});

test('recipient can reject a pending trade and release escrow', function (): void {
    [$initiator, $recipient, $guild] = tradeParticipants();
    $offeredInventoryItem = inventoryItemFor($initiator, 'Reject Sword', 100, 1);
    $requestedInventoryItem = inventoryItemFor($recipient, 'Reject Shield', 100, 1);
    $service = app(TradeService::class);
    $trade = proposedTrade($service, $initiator, $recipient, $guild, $offeredInventoryItem, $requestedInventoryItem);

    $rejectedTrade = $service->reject($trade, $recipient);

    expect($rejectedTrade->status)->toBe(Trade::STATUS_REJECTED)
        ->and($offeredInventoryItem->refresh()->user_id)->toBe($initiator->id)
        ->and($offeredInventoryItem->is_in_escrow)->toBeFalse()
        ->and($requestedInventoryItem->refresh()->user_id)->toBe($recipient->id)
        ->and($trade->escrowItems()->count())->toBe(0);
});

test('only recipient can reject and only while pending', function (): void {
    [$initiator, $recipient, $guild] = tradeParticipants();
    $offeredInventoryItem = inventoryItemFor($initiator, 'Recipient Reject Sword', 100, 1);
    $requestedInventoryItem = inventoryItemFor($recipient, 'Recipient Reject Shield', 100, 1);
    $service = app(TradeService::class);
    $trade = proposedTrade($service, $initiator, $recipient, $guild, $offeredInventoryItem, $requestedInventoryItem);

    expect(fn () => $service->reject($trade, $initiator))
        ->toThrow(ValidationException::class, 'Only the recipient can reject this trade.');

    $service->accept($trade, $recipient);

    expect(fn () => $service->reject($trade, $recipient))
        ->toThrow(ValidationException::class, 'Trade is no longer pending.');
});

test('initiator can cancel a pending trade and release escrow', function (): void {
    [$initiator, $recipient, $guild] = tradeParticipants();
    $offeredInventoryItem = inventoryItemFor($initiator, 'Cancel Sword', 100, 1);
    $requestedInventoryItem = inventoryItemFor($recipient, 'Cancel Shield', 100, 1);
    $service = app(TradeService::class);
    $trade = proposedTrade($service, $initiator, $recipient, $guild, $offeredInventoryItem, $requestedInventoryItem);

    $cancelledTrade = $service->cancel($trade, $initiator);

    expect($cancelledTrade->status)->toBe(Trade::STATUS_CANCELLED)
        ->and($offeredInventoryItem->refresh()->user_id)->toBe($initiator->id)
        ->and($offeredInventoryItem->is_in_escrow)->toBeFalse()
        ->and($requestedInventoryItem->refresh()->user_id)->toBe($recipient->id)
        ->and($trade->escrowItems()->count())->toBe(0);
});

test('only initiator can cancel and only while pending', function (): void {
    [$initiator, $recipient, $guild] = tradeParticipants();
    $offeredInventoryItem = inventoryItemFor($initiator, 'Initiator Cancel Sword', 100, 1);
    $requestedInventoryItem = inventoryItemFor($recipient, 'Initiator Cancel Shield', 100, 1);
    $service = app(TradeService::class);
    $trade = proposedTrade($service, $initiator, $recipient, $guild, $offeredInventoryItem, $requestedInventoryItem);

    expect(fn () => $service->cancel($trade, $recipient))
        ->toThrow(ValidationException::class, 'Only the initiator can cancel this trade.');

    $service->accept($trade, $recipient);

    expect(fn () => $service->cancel($trade, $initiator))
        ->toThrow(ValidationException::class, 'Trade is no longer pending.');
});

test('pending trade can expire and release escrow', function (): void {
    [$initiator, $recipient, $guild] = tradeParticipants();
    $offeredInventoryItem = inventoryItemFor($initiator, 'Expired Sword', 100, 1);
    $requestedInventoryItem = inventoryItemFor($recipient, 'Expired Shield', 100, 1);
    $service = app(TradeService::class);
    $trade = proposedTrade($service, $initiator, $recipient, $guild, $offeredInventoryItem, $requestedInventoryItem);

    $expiredTrade = $service->expireIfPending($trade);

    expect($expiredTrade?->status)->toBe(Trade::STATUS_EXPIRED)
        ->and($offeredInventoryItem->refresh()->user_id)->toBe($initiator->id)
        ->and($offeredInventoryItem->is_in_escrow)->toBeFalse()
        ->and($requestedInventoryItem->refresh()->user_id)->toBe($recipient->id)
        ->and($trade->escrowItems()->count())->toBe(0);
});

test('expire leaves resolved trades unchanged', function (): void {
    [$initiator, $recipient, $guild] = tradeParticipants();
    $offeredInventoryItem = inventoryItemFor($initiator, 'Resolved Sword', 100, 1);
    $requestedInventoryItem = inventoryItemFor($recipient, 'Resolved Shield', 100, 1);
    $service = app(TradeService::class);
    $trade = proposedTrade($service, $initiator, $recipient, $guild, $offeredInventoryItem, $requestedInventoryItem);

    $service->accept($trade, $recipient);
    $expiredTrade = $service->expireIfPending($trade);

    expect($expiredTrade?->status)->toBe(Trade::STATUS_COMPLETED)
        ->and($trade->refresh()->status)->toBe(Trade::STATUS_COMPLETED)
        ->and($trade->escrowItems()->count())->toBe(0);
});

/**
 * @return array{0: User, 1: User, 2: \App\Models\Guild}
 */
function tradeParticipants(): array
{
    $initiator = User::factory()->create();
    $recipient = User::factory()->create();
    $guild = createTestGuild($initiator);
    attachTestGuildMember($guild, $recipient);

    return [$initiator, $recipient, $guild];
}

function inventoryItemFor(User $user, string $name, int $baseValue, int $quantity): InventoryItem
{
    $item = Item::query()->create([
        'name' => $name,
        'base_value' => $baseValue,
        'is_unique' => false,
    ]);

    return InventoryItem::query()->create([
        'user_id' => $user->id,
        'item_id' => $item->id,
        'quantity' => $quantity,
        'is_tradable' => true,
        'is_in_escrow' => false,
    ]);
}

function proposedTrade(
    TradeService $service,
    User $initiator,
    User $recipient,
    App\Models\Guild $guild,
    InventoryItem $offeredInventoryItem,
    InventoryItem $requestedInventoryItem
): Trade {
    return $service->propose($initiator, [
        'recipient_id' => $recipient->id,
        'guild_id' => $guild->id,
        'offered_items' => [
            ['inventory_item_id' => $offeredInventoryItem->id, 'quantity' => 1],
        ],
        'requested_items' => [
            ['inventory_item_id' => $requestedInventoryItem->id, 'quantity' => 1],
        ],
    ]);
}
