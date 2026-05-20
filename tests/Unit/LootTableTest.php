<?php

namespace Tests\Unit;

use App\Services\LootTable;
use Tests\TestCase;

class LootTableTest extends TestCase
{
    public function test_it_resolves_configured_loot_items_from_the_container(): void
    {
        config()->set('loot.items', [
            [
                'name' => 'Guaranteed Ring',
                'weight' => 1,
                'rarity' => 'legendary',
                'stackable' => false,
                'max_stack' => 1,
            ],
        ]);

        $loot = app(LootTable::class)->roll();

        $this->assertSame('Guaranteed Ring', $loot['name']);
    }

    public function test_legendary_multiplier_only_changes_legendary_weights(): void
    {
        $lootTable = new LootTable([
            [
                'name' => 'Guaranteed Sword',
                'weight' => 1,
                'rarity' => 'common',
                'stackable' => false,
                'max_stack' => 1,
            ],
            [
                'name' => 'Zero Weight Ring',
                'weight' => 0,
                'rarity' => 'legendary',
                'stackable' => false,
                'max_stack' => 1,
            ],
        ]);

        $loot = $lootTable->roll(100.0);

        $this->assertSame('Guaranteed Sword', $loot['name']);
    }
}
