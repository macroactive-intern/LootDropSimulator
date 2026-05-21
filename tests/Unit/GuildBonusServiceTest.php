<?php

use App\Models\Guild;
use App\Models\User;
use App\Services\GuildBonusService;
use App\Services\LootTable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

function createGuildBonusServiceGuild(User $creator): Guild
{
    return Guild::query()->create([
        'name' => 'Bonus Guild',
        'created_by' => $creator->id,
        'is_open' => true,
    ]);
}

test('leaders in any guild receive the configured legendary multiplier globally', function (): void {
    config()->set('loot.guild_leader_legendary_multiplier', 2.0);

    $leader = User::factory()->create();
    $member = User::factory()->create();
    $guild = createGuildBonusServiceGuild($leader);
    $guild->users()->attach($leader->id, [
        'role' => 'leader',
        'joined_at' => now(),
    ]);
    $guild->users()->attach($member->id, [
        'role' => 'member',
        'joined_at' => now(),
    ]);

    $service = app(GuildBonusService::class);

    expect($service->getMultiplierForUser($leader->id))->toBe(2.0)
        ->and($service->getMultiplierForUser($member->id))->toBe(1.0);
});

test('guild leader multiplier lookup uses a single existence query', function (): void {
    $leader = User::factory()->create();
    $guild = createGuildBonusServiceGuild($leader);
    $guild->users()->attach($leader->id, [
        'role' => 'leader',
        'joined_at' => now(),
    ]);

    DB::flushQueryLog();
    DB::enableQueryLog();

    app(GuildBonusService::class)->getMultiplierForUser($leader->id);

    expect(DB::getQueryLog())->toHaveCount(1);

    DB::disableQueryLog();
});

test('guild leader multiplier is intentionally not scoped to loot source', function (): void {
    config()->set('loot.guild_leader_legendary_multiplier', 2.0);

    $leader = User::factory()->create();
    $guild = createGuildBonusServiceGuild($leader);
    $guild->users()->attach($leader->id, [
        'role' => 'leader',
        'joined_at' => now(),
    ]);

    expect(app(GuildBonusService::class)->getMultiplierForUser($leader->id))->toBe(2.0);
});

test('guild leader multiplier increases legendary drop rate', function (): void {
    config()->set('loot.items', [
        [
            'name' => 'Common Sword',
            'weight' => 95,
            'rarity' => 'common',
            'stackable' => false,
            'max_stack' => 1,
        ],
        [
            'name' => 'Legendary Ring',
            'weight' => 5,
            'rarity' => 'legendary',
            'stackable' => false,
            'max_stack' => 1,
        ],
    ]);
    config()->set('loot.guild_leader_legendary_multiplier', 2.0);
    mt_srand(20260521);

    $leader = User::factory()->create();
    $guild = createGuildBonusServiceGuild($leader);
    $guild->users()->attach($leader->id, [
        'role' => 'leader',
        'joined_at' => now(),
    ]);

    $lootTable = new LootTable(config('loot.items'));
    $baseCounts = rollGuildBonusRarities($lootTable, 1.0);
    $leaderCounts = rollGuildBonusRarities(
        $lootTable,
        app(GuildBonusService::class)->getMultiplierForUser($leader->id),
    );

    expect($leaderCounts['legendary'])->toBeGreaterThan($baseCounts['legendary']);
});

function rollGuildBonusRarities(LootTable $lootTable, float $legendaryMultiplier): array
{
    $counts = [
        'common' => 0,
        'legendary' => 0,
    ];

    for ($roll = 0; $roll < 10000; $roll++) {
        $loot = $lootTable->roll($legendaryMultiplier);
        $counts[$loot['rarity']]++;
    }

    return $counts;
}
