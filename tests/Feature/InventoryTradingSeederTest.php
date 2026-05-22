<?php

use App\Models\Guild;
use App\Models\InventoryItem;
use App\Models\Item;
use App\Models\User;
use Database\Seeders\InventoryTradingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('inventory trading seeder creates tradeable inventory fixtures', function (): void {
    $this->seed(InventoryTradingSeeder::class);

    $initiator = User::query()->where('email', 'trade.initiator@example.com')->firstOrFail();
    $recipient = User::query()->where('email', 'trade.recipient@example.com')->firstOrFail();
    $outsider = User::query()->where('email', 'trade.outsider@example.com')->firstOrFail();
    $guild = Guild::query()->where('name', 'L8 Trading Test Guild')->firstOrFail();

    expect($guild->users()->whereKey($initiator->id)->exists())->toBeTrue()
        ->and($guild->users()->whereKey($recipient->id)->exists())->toBeTrue()
        ->and($guild->users()->whereKey($outsider->id)->exists())->toBeFalse();

    expect(Item::query()->where('name', 'Iron Sword')->value('base_value'))->toBe(100)
        ->and(Item::query()->where('name', 'Health Potion')->value('base_value'))->toBe(25)
        ->and(Item::query()->where('name', 'Ancient Relic')->value('base_value'))->toBe(300)
        ->and(Item::query()->where('name', 'Ancient Relic')->value('is_unique'))->toBeTrue()
        ->and(Item::query()->where('name', 'Leather Armor')->value('base_value'))->toBe(120);

    $healthPotion = Item::query()->where('name', 'Health Potion')->firstOrFail();
    $ancientRelic = Item::query()->where('name', 'Ancient Relic')->firstOrFail();

    expect(InventoryItem::query()
        ->where('item_id', $healthPotion->id)
        ->where('quantity', '>', 1)
        ->exists())->toBeTrue();

    InventoryItem::query()
        ->where('item_id', $ancientRelic->id)
        ->get()
        ->each(fn (InventoryItem $inventoryItem) => expect($inventoryItem->quantity)->toBe(1));
});
