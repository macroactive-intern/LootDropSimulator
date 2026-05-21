<?php

use App\Models\Guild;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function createGuildMemberControllerGuild(User $creator): Guild
{
    $guild = Guild::query()->create([
        'name' => 'Member Controller Guild',
        'created_by' => $creator->id,
        'is_open' => true,
    ]);

    $guild->users()->attach($creator->id, [
        'role' => 'leader',
        'joined_at' => now(),
    ]);

    return $guild;
}

function attachGuildMemberControllerMember(Guild $guild, User $user, string $role = 'member'): void
{
    $guild->users()->attach($user->id, [
        'role' => $role,
        'joined_at' => now(),
    ]);
}

test('guild member endpoints require authentication', function (): void {
    $leader = User::factory()->create();
    $member = User::factory()->create();
    $guild = createGuildMemberControllerGuild($leader);
    attachGuildMemberControllerMember($guild, $member);

    $this->deleteJson('/api/guilds/'.$guild->id.'/members/'.$member->id)
        ->assertUnauthorized();
});

test('leaders can kick guild members', function (): void {
    $leader = User::factory()->create();
    $member = User::factory()->create();
    $guild = createGuildMemberControllerGuild($leader);
    attachGuildMemberControllerMember($guild, $member);

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
    $guild = createGuildMemberControllerGuild($leader);
    attachGuildMemberControllerMember($guild, $officer, 'officer');
    attachGuildMemberControllerMember($guild, $otherOfficer, 'officer');
    attachGuildMemberControllerMember($guild, $member);

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
    $guild = createGuildMemberControllerGuild($leader);
    attachGuildMemberControllerMember($guild, $member);

    $this->actingAs($leader)
        ->patchJson('/api/guilds/'.$guild->id.'/members/'.$member->id.'/role', [
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
    $guild = createGuildMemberControllerGuild($leader);
    attachGuildMemberControllerMember($guild, $member);
    attachGuildMemberControllerMember($guild, $target);

    $this->actingAs($member)
        ->patchJson('/api/guilds/'.$guild->id.'/members/'.$target->id.'/role', [
            'role' => 'officer',
        ])
        ->assertForbidden();

    expect($guild->users()->whereKey($target->id)->firstOrFail()->pivot->role)
        ->toBe('member');
});

test('role changes validate allowed guild roles', function (): void {
    $leader = User::factory()->create();
    $member = User::factory()->create();
    $guild = createGuildMemberControllerGuild($leader);
    attachGuildMemberControllerMember($guild, $member);

    $this->actingAs($leader)
        ->patchJson('/api/guilds/'.$guild->id.'/members/'.$member->id.'/role', [
            'role' => 'raid-boss',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('role');
});
