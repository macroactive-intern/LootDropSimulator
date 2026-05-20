<?php

namespace Tests\Unit;

use App\Services\LootTable;
use Tests\TestCase;

class LootTableTest extends TestCase
{
    private const ROLL_COUNT = 10000;

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

    public function test_configured_rarity_distribution_matches_weight_order(): void
    {
        mt_srand(1234);

        $counts = $this->rollRarities(new LootTable($this->configuredItems()));
        $this->writePercentages('Base loot odds', $counts);

        $this->assertGreaterThan($counts['rare'], $counts['common']);
        $this->assertGreaterThan($counts['epic'], $counts['rare']);
        $this->assertGreaterThan($counts['legendary'], $counts['epic']);
    }

    public function test_legendary_multiplier_increases_legendary_odds(): void
    {
        mt_srand(5678);

        $lootTable = new LootTable($this->configuredItems());
        $baseCounts = $this->rollRarities($lootTable);
        $multipliedCounts = $this->rollRarities(
            $lootTable,
            config('loot.guild_leader_legendary_multiplier')
        );

        $this->writePercentages('Base legendary odds', $baseCounts);
        $this->writePercentages('Multiplied legendary odds', $multipliedCounts);

        $this->assertGreaterThan(
            $baseCounts['legendary'],
            $multipliedCounts['legendary']
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function configuredItems(): array
    {
        return (require config_path('loot.php'))['items'];
    }

    /**
     * @return array<string, int>
     */
    private function rollRarities(LootTable $lootTable, float $legendaryMultiplier = 1.0): array
    {
        $counts = [
            'common' => 0,
            'rare' => 0,
            'epic' => 0,
            'legendary' => 0,
        ];

        for ($roll = 0; $roll < self::ROLL_COUNT; $roll++) {
            $loot = $lootTable->roll($legendaryMultiplier);
            $counts[$loot['rarity']]++;
        }

        return $counts;
    }

    /**
     * @param  array<string, int>  $counts
     */
    private function writePercentages(string $label, array $counts): void
    {
        fwrite(STDERR, PHP_EOL.$label.':'.PHP_EOL);

        foreach ($counts as $rarity => $count) {
            fwrite(
                STDERR,
                sprintf('  %s: %.2f%% (%d/%d)%s', $rarity, ($count / self::ROLL_COUNT) * 100, $count, self::ROLL_COUNT, PHP_EOL)
            );
        }
    }
}
