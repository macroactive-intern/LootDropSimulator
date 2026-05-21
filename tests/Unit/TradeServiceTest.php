<?php

use App\Models\InventoryItem;
use App\Models\Item;
use App\Models\Trade;
use App\Models\User;
use App\Services\TradeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

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
