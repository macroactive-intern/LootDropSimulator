<?php

use App\Models\Guild;
use App\Models\GuildEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function createGuildTreasuryControllerGuild(User $creator): Guild
{
    $guild = Guild::query()->create([
        'name' => 'Treasury Controller Guild',
        'created_by' => $creator->id,
        'is_open' => true,
    ]);

    $guild->users()->attach($creator->id, [
        'role' => 'leader',
        'joined_at' => now(),
    ]);

    return $guild;
}

function attachGuildTreasuryControllerMember(Guild $guild, User $user, string $role = 'member'): void
{
    $guild->users()->attach($user->id, [
        'role' => $role,
        'joined_at' => now(),
    ]);
}

test('guild treasury endpoints require authentication', function (): void {
    $leader = User::factory()->create();
    $guild = createGuildTreasuryControllerGuild($leader);

    $this->postJson('/api/guilds/'.$guild->id.'/treasury/deposit', [
        'amount' => 100,
    ])->assertUnauthorized();
});

test('guild members can deposit into the treasury', function (): void {
    $leader = User::factory()->create();
    $member = User::factory()->create();
    $guild = createGuildTreasuryControllerGuild($leader);
    attachGuildTreasuryControllerMember($guild, $member);

    $this->actingAs($member)
        ->postJson('/api/guilds/'.$guild->id.'/treasury/deposit', [
            'amount' => 125,
        ])
        ->assertOk()
        ->assertJsonPath('data.id', $guild->id)
        ->assertJsonPath('data.treasury_balance', 125);

    $contributor = $guild->users()->whereKey($member->id)->firstOrFail();

    expect($guild->refresh()->treasury_balance)->toBe(125)
        ->and($contributor->pivot->contributed_gold)->toBe(125);
});

test('non members cannot deposit into the treasury', function (): void {
    $leader = User::factory()->create();
    $outsider = User::factory()->create();
    $guild = createGuildTreasuryControllerGuild($leader);

    $this->actingAs($outsider)
        ->postJson('/api/guilds/'.$guild->id.'/treasury/deposit', [
            'amount' => 125,
        ])
        ->assertForbidden();

    expect($guild->refresh()->treasury_balance)->toBe(0);
});

test('leaders can withdraw from the treasury', function (): void {
    $leader = User::factory()->create();
    $guild = createGuildTreasuryControllerGuild($leader);
    $guild->forceFill(['treasury_balance' => 300])->save();

    $this->actingAs($leader)
        ->postJson('/api/guilds/'.$guild->id.'/treasury/withdraw', [
            'amount' => 100,
            'reason' => 'Buy raid supplies',
        ])
        ->assertOk()
        ->assertJsonPath('data.id', $guild->id)
        ->assertJsonPath('data.treasury_balance', 200);

    $event = GuildEvent::query()
        ->where('guild_id', $guild->id)
        ->where('event_type', 'withdraw')
        ->firstOrFail();

    expect($guild->refresh()->treasury_balance)->toBe(200)
        ->and($event->metadata['amount'])->toBe(100)
        ->and($event->metadata['reason'])->toBe('Buy raid supplies');
});

test('members cannot withdraw from the treasury', function (): void {
    $leader = User::factory()->create();
    $member = User::factory()->create();
    $guild = createGuildTreasuryControllerGuild($leader);
    attachGuildTreasuryControllerMember($guild, $member);
    $guild->forceFill(['treasury_balance' => 300])->save();

    $this->actingAs($member)
        ->postJson('/api/guilds/'.$guild->id.'/treasury/withdraw', [
            'amount' => 100,
            'reason' => 'Unauthorized purchase',
        ])
        ->assertForbidden();

    expect($guild->refresh()->treasury_balance)->toBe(300);
});

test('treasury requests validate payloads', function (): void {
    $leader = User::factory()->create();
    $guild = createGuildTreasuryControllerGuild($leader);

    $this->actingAs($leader)
        ->postJson('/api/guilds/'.$guild->id.'/treasury/deposit', [
            'amount' => 0,
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('amount');

    $this->actingAs($leader)
        ->postJson('/api/guilds/'.$guild->id.'/treasury/withdraw', [
            'amount' => 50,
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('reason');
});
