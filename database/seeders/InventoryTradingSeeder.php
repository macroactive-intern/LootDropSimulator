<?php

namespace Database\Seeders;

use App\Models\Guild;
use App\Models\GuildMember;
use App\Models\InventoryItem;
use App\Models\Item;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class InventoryTradingSeeder extends Seeder
{
    /**
     * Seed test data for the Inventory + Trading System.
     */
    public function run(): void
    {
        $initiator = User::query()->updateOrCreate(
            ['email' => 'trade.initiator@example.com'],
            [
                'name' => 'Trade Initiator',
                'password' => Hash::make('password'),
                'email_verified_at' => now(),
            ]
        );

        $recipient = User::query()->updateOrCreate(
            ['email' => 'trade.recipient@example.com'],
            [
                'name' => 'Trade Recipient',
                'password' => Hash::make('password'),
                'email_verified_at' => now(),
            ]
        );

        $outsider = User::query()->updateOrCreate(
            ['email' => 'trade.outsider@example.com'],
            [
                'name' => 'Trade Outsider',
                'password' => Hash::make('password'),
                'email_verified_at' => now(),
            ]
        );

        $items = collect([
            [
                'name' => 'Iron Sword',
                'description' => 'A reliable blade for early trading tests.',
                'base_value' => 100,
                'is_unique' => false,
            ],
            [
                'name' => 'Health Potion',
                'description' => 'A stackable restorative potion.',
                'base_value' => 25,
                'is_unique' => false,
            ],
            [
                'name' => 'Ancient Relic',
                'description' => 'A rare relic that cannot be stacked.',
                'base_value' => 300,
                'is_unique' => true,
            ],
            [
                'name' => 'Leather Armor',
                'description' => 'Light armor with steady trade value.',
                'base_value' => 120,
                'is_unique' => false,
            ],
        ])->mapWithKeys(fn (array $item): array => [
            $item['name'] => Item::query()->updateOrCreate(
                ['name' => $item['name']],
                [
                    'description' => $item['description'],
                    'base_value' => $item['base_value'],
                    'is_unique' => $item['is_unique'],
                ]
            ),
        ]);

        $guild = Guild::query()->updateOrCreate(
            ['name' => 'L8 Trading Test Guild'],
            [
                'description' => 'Shared guild for testing inventory trades.',
                'created_by' => $initiator->id,
                'treasury_balance' => 0,
                'is_open' => true,
            ]
        );

        GuildMember::query()->updateOrCreate(
            ['guild_id' => $guild->id, 'user_id' => $initiator->id],
            [
                'role' => 'leader',
                'joined_at' => now(),
                'contributed_gold' => 0,
            ]
        );

        GuildMember::query()->updateOrCreate(
            ['guild_id' => $guild->id, 'user_id' => $recipient->id],
            [
                'role' => 'member',
                'joined_at' => now(),
                'contributed_gold' => 0,
            ]
        );

        $this->seedInventory($initiator, [
            ['item' => $items['Iron Sword'], 'quantity' => 1],
            ['item' => $items['Health Potion'], 'quantity' => 6],
            ['item' => $items['Ancient Relic'], 'quantity' => 1],
        ]);

        $this->seedInventory($recipient, [
            ['item' => $items['Leather Armor'], 'quantity' => 1],
            ['item' => $items['Health Potion'], 'quantity' => 8],
            ['item' => $items['Iron Sword'], 'quantity' => 2],
            ['item' => $items['Ancient Relic'], 'quantity' => 1],
        ]);

        $this->seedInventory($outsider, [
            ['item' => $items['Leather Armor'], 'quantity' => 1],
            ['item' => $items['Health Potion'], 'quantity' => 3],
        ]);
    }

    /**
     * @param  array<int, array{item: Item, quantity: int}>  $inventory
     */
    private function seedInventory(User $user, array $inventory): void
    {
        foreach ($inventory as $inventoryItem) {
            InventoryItem::query()->updateOrCreate(
                [
                    'user_id' => $user->id,
                    'item_id' => $inventoryItem['item']->id,
                ],
                [
                    'quantity' => $inventoryItem['quantity'],
                    'is_tradable' => true,
                    'is_in_escrow' => false,
                ]
            );
        }
    }
}
