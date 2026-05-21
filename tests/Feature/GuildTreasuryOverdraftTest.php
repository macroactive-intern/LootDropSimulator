<?php

use App\Models\Guild;
use App\Models\GuildEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function createGuildTreasuryOverdraftGuild(User $leader, int $balance): Guild
{
    $guild = Guild::query()->create([
        'name' => 'Overdraft Guard Guild '.str()->uuid(),
        'created_by' => $leader->id,
        'treasury_balance' => $balance,
        'is_open' => true,
    ]);

    $guild->users()->attach($leader->id, [
        'role' => 'leader',
        'joined_at' => now(),
    ]);

    return $guild;
}

test('competing treasury withdrawals cannot overdraw the balance', function (): void {
    $leader = User::factory()->create();
    $guild = createGuildTreasuryOverdraftGuild($leader, 100);

    $firstWithdrawal = $this->actingAs($leader)
        ->postJson('/api/guilds/'.$guild->id.'/treasury/withdraw', [
            'amount' => 80,
            'reason' => 'First competing withdrawal',
        ]);

    $secondWithdrawal = $this->actingAs($leader)
        ->postJson('/api/guilds/'.$guild->id.'/treasury/withdraw', [
            'amount' => 80,
            'reason' => 'Second competing withdrawal',
        ]);

    $firstWithdrawal
        ->assertOk()
        ->assertJsonPath('data.treasury_balance', 20);
    $secondWithdrawal
        ->assertUnprocessable()
        ->assertJsonValidationErrors('amount');

    expect($guild->refresh()->treasury_balance)->toBe(20)
        ->and($guild->treasury_balance)->toBeGreaterThanOrEqual(0)
        ->and(GuildEvent::query()
            ->where('guild_id', $guild->id)
            ->where('event_type', 'withdraw')
            ->count())->toBe(1);
});

test('treasury overdraft protection still uses a row lock before balance updates', function (): void {
    $source = file_get_contents(app_path('Services/GuildService.php'));
    $withdrawMethodStart = strpos($source, 'public function withdrawTreasury');
    $lockPosition = strpos($source, '->lockForUpdate()', $withdrawMethodStart);
    $updatePosition = strpos($source, "->update(['treasury_balance' => \$balanceAfter])", $withdrawMethodStart);

    expect($withdrawMethodStart)->not->toBeFalse()
        ->and($lockPosition)->not->toBeFalse()
        ->and($updatePosition)->not->toBeFalse()
        ->and($lockPosition)->toBeLessThan($updatePosition);
});

test('failed competing withdrawals leave treasury balance non negative', function (): void {
    $leader = User::factory()->create();
    $guild = createGuildTreasuryOverdraftGuild($leader, 50);

    for ($attempt = 1; $attempt <= 3; $attempt++) {
        $this->actingAs($leader)
            ->postJson('/api/guilds/'.$guild->id.'/treasury/withdraw', [
                'amount' => 75,
                'reason' => 'Too large competing withdrawal '.$attempt,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('amount');
    }

    expect($guild->refresh()->treasury_balance)->toBe(50)
        ->and($guild->treasury_balance)->toBeGreaterThanOrEqual(0)
        ->and(GuildEvent::query()
            ->where('guild_id', $guild->id)
            ->where('event_type', 'withdraw')
            ->exists())->toBeFalse();
});
