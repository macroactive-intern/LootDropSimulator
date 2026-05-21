<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('guild member endpoints require authentication', function (): void {
    $leader = User::factory()->create();
    $member = User::factory()->create();
    $guild = createTestGuild($leader, ['name' => 'Member Controller Guild']);
    attachTestGuildMember($guild, $member);

    $this->deleteJson('/api/guilds/'.$guild->id.'/members/'.$member->id)
        ->assertUnauthorized();
});

test('leaders can kick guild members', function (): void {
    $leader = User::factory()->create();
    $member = User::factory()->create();
    $guild = createTestGuild($leader, ['name' => 'Member Controller Guild']);
    attachTestGuildMember($guild, $member);

    $this->actingAs($leader)
        ->deleteJson('/api/guilds/'.$guild->id.'/members/'.$member->id)
        ->assertNoContent();

    expect($guild->users()->whereKey($member->id)->exists())->toBeFalse();
});

test('officers can only kick regular members', function (): void {
    $leader = User::factory()->create();
    $officer = User::factory()->create();
    $otherOfficer = User::factory()->create();
    $member = User::factory()->create();
    $guild = createTestGuild($leader, ['name' => 'Member Controller Guild']);
    attachTestGuildMember($guild, $officer, 'officer');
    attachTestGuildMember($guild, $otherOfficer, 'officer');
    attachTestGuildMember($guild, $member);

    $this->actingAs($officer)
        ->deleteJson('/api/guilds/'.$guild->id.'/members/'.$otherOfficer->id)
        ->assertForbidden();

    $this->actingAs($officer)
        ->deleteJson('/api/guilds/'.$guild->id.'/members/'.$member->id)
        ->assertNoContent();

    expect($guild->users()->whereKey($otherOfficer->id)->exists())->toBeTrue()
        ->and($guild->users()->whereKey($member->id)->exists())->toBeFalse();
});

test('leaders can change member roles', function (): void {
    $leader = User::factory()->create();
    $member = User::factory()->create(['name' => 'Promoted Member']);
    $guild = createTestGuild($leader, ['name' => 'Member Controller Guild']);
    attachTestGuildMember($guild, $member);

    $this->actingAs($leader)
        ->putJson('/api/guilds/'.$guild->id.'/members/'.$member->id, [
            'role' => 'officer',
        ])
        ->assertOk()
        ->assertJsonPath('data.id', $member->id)
        ->assertJsonPath('data.name', 'Promoted Member')
        ->assertJsonPath('data.role', 'officer');

    expect($guild->users()->whereKey($member->id)->firstOrFail()->pivot->role)
        ->toBe('officer');
});

test('members cannot change roles', function (): void {
    $leader = User::factory()->create();
    $member = User::factory()->create();
    $target = User::factory()->create();
    $guild = createTestGuild($leader, ['name' => 'Member Controller Guild']);
    attachTestGuildMember($guild, $member);
    attachTestGuildMember($guild, $target);

    $this->actingAs($member)
        ->putJson('/api/guilds/'.$guild->id.'/members/'.$target->id, [
            'role' => 'officer',
        ])
        ->assertForbidden();

    expect($guild->users()->whereKey($target->id)->firstOrFail()->pivot->role)
        ->toBe('member');
});

test('role changes validate allowed guild roles', function (): void {
    $leader = User::factory()->create();
    $member = User::factory()->create();
    $guild = createTestGuild($leader, ['name' => 'Member Controller Guild']);
    attachTestGuildMember($guild, $member);

    $this->actingAs($leader)
        ->putJson('/api/guilds/'.$guild->id.'/members/'.$member->id, [
            'role' => 'raid-boss',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('role');
});
