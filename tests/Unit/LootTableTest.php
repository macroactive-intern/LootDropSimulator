<?php

use App\Services\LootTable;

const ROLL_COUNT = 10000;

function configuredLootItems(): array
{
    return (require config_path('loot.php'))['items'];
}

function rollRarities(LootTable $lootTable, float $legendaryMultiplier = 1.0): array
{
    $counts = [
        'common' => 0,
        'rare' => 0,
        'epic' => 0,
        'legendary' => 0,
    ];

    for ($roll = 0; $roll < ROLL_COUNT; $roll++) {
        $loot = $lootTable->roll($legendaryMultiplier);
        $counts[$loot['rarity']]++;
    }

    return $counts;
}

function writeLootPercentages(string $label, array $counts): void
{
    fwrite(STDERR, PHP_EOL.$label.':'.PHP_EOL);

    foreach ($counts as $rarity => $count) {
        fwrite(
            STDERR,
            sprintf('  %s: %.2f%% (%d/%d)%s', $rarity, ($count / ROLL_COUNT) * 100, $count, ROLL_COUNT, PHP_EOL)
        );
    }
}

test('it resolves configured loot items from the container', function (): void {
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

    expect($loot['name'])->toBe('Guaranteed Ring');
});

test('legendary multiplier only changes legendary weights', function (): void {
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

    expect($loot['name'])->toBe('Guaranteed Sword');
});

test('it throws when no loot items can be rolled', function (): void {
    $lootTable = new LootTable([]);

    expect(fn () => $lootTable->roll())
        ->toThrow(UnexpectedValueException::class, 'Loot table has no rollable items.');
});

test('it throws when configured loot weights are not rollable', function (): void {
    $lootTable = new LootTable([
        [
            'name' => 'Weightless Sword',
            'weight' => 0,
            'rarity' => 'common',
            'stackable' => false,
            'max_stack' => 1,
        ],
    ]);

    expect(fn () => $lootTable->roll())
        ->toThrow(UnexpectedValueException::class, 'Loot table has no rollable items.');
});

test('configured rarity distribution matches weight order', function (): void {
    mt_srand(1234);

    $counts = rollRarities(new LootTable(configuredLootItems()));
    writeLootPercentages('Base loot odds', $counts);

    expect($counts['common'])->toBeGreaterThan($counts['rare'])
        ->and($counts['rare'])->toBeGreaterThan($counts['epic'])
        ->and($counts['epic'])->toBeGreaterThan($counts['legendary']);
});

test('legendary multiplier increases legendary odds', function (): void {
    mt_srand(5678);

    $lootTable = new LootTable(configuredLootItems());
    $baseCounts = rollRarities($lootTable);
    $multipliedCounts = rollRarities(
        $lootTable,
        config('loot.guild_leader_legendary_multiplier')
    );

    writeLootPercentages('Base legendary odds', $baseCounts);
    writeLootPercentages('Multiplied legendary odds', $multipliedCounts);

    expect($multipliedCounts['legendary'])->toBeGreaterThan($baseCounts['legendary']);
});
