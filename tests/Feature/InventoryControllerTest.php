<?php

use App\Models\InventoryItem;
use App\Models\Item;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('inventory endpoint requires authentication', function (): void {
    $this->getJson('/api/inventory')->assertUnauthorized();
});

test('authenticated users can list only their inventory with escrow state', function (): void {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $olderItem = inventoryControllerItem('Older Potion', 25);
    $newerItem = inventoryControllerItem('Locked Relic', 100);
    $otherItem = inventoryControllerItem('Other User Item', 50);
    $this->travelTo(now()->subDay());
    $olderInventoryItem = InventoryItem::query()->create([
        'user_id' => $user->id,
        'item_id' => $olderItem->id,
        'quantity' => 3,
        'is_tradable' => true,
        'is_in_escrow' => false,
    ]);
    $this->travelBack();

    $newerInventoryItem = InventoryItem::query()->create([
        'user_id' => $user->id,
        'item_id' => $newerItem->id,
        'quantity' => 1,
        'is_tradable' => true,
        'is_in_escrow' => true,
    ]);
    InventoryItem::query()->create([
        'user_id' => $otherUser->id,
        'item_id' => $otherItem->id,
        'quantity' => 9,
        'is_tradable' => true,
        'is_in_escrow' => false,
    ]);

    $this->actingAs($user)
        ->getJson('/api/inventory')
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.id', $newerInventoryItem->id)
        ->assertJsonPath('data.0.item_name', 'Locked Relic')
        ->assertJsonPath('data.0.is_in_escrow', true)
        ->assertJsonPath('data.1.id', $olderInventoryItem->id)
        ->assertJsonPath('data.1.is_in_escrow', false)
        ->assertJsonMissingPath('data.2')
        ->assertJsonStructure([
            'data' => [
                [
                    'id',
                    'item_id',
                    'item_name',
                    'description',
                    'base_value',
                    'is_unique',
                    'quantity',
                    'is_tradable',
                    'is_in_escrow',
                ],
            ],
            'links',
            'meta',
        ]);
});

function inventoryControllerItem(string $name, int $baseValue): Item
{
    return Item::query()->create([
        'name' => $name,
        'description' => $name.' description',
        'base_value' => $baseValue,
        'is_unique' => false,
    ]);
}
