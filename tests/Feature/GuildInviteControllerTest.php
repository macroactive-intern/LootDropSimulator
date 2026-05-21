<?php

use App\Models\Guild;
use App\Models\GuildInvite;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function createGuildInviteControllerGuild(User $creator): Guild
{
    $guild = Guild::query()->create([
        'name' => 'Invite Controller Guild',
        'created_by' => $creator->id,
        'is_open' => false,
    ]);

    $guild->users()->attach($creator->id, [
        'role' => 'leader',
        'joined_at' => now(),
    ]);

    return $guild;
}

function attachGuildInviteControllerMember(Guild $guild, User $user, string $role = 'member'): void
{
    $guild->users()->attach($user->id, [
        'role' => $role,
        'joined_at' => now(),
    ]);
}

test('leaders and officers can create guild invites', function (): void {
    $leader = User::factory()->create();
    $officer = User::factory()->create();
    $guild = createGuildInviteControllerGuild($leader);
    attachGuildInviteControllerMember($guild, $officer, 'officer');

    $this->actingAs($officer)
        ->postJson('/api/guilds/'.$guild->id.'/invites', [
            'email' => 'new-member@example.com',
        ])
        ->assertCreated()
        ->assertJsonPath('data.guild_id', $guild->id)
        ->assertJsonPath('data.invited_by', $officer->id)
        ->assertJsonPath('data.email', 'new-member@example.com')
        ->assertJsonPath('data.accepted_at', null);

    expect(GuildInvite::query()
        ->where('guild_id', $guild->id)
        ->where('email', 'new-member@example.com')
        ->exists())->toBeTrue();
});

test('regular members cannot create guild invites', function (): void {
    $leader = User::factory()->create();
    $member = User::factory()->create();
    $guild = createGuildInviteControllerGuild($leader);
    attachGuildInviteControllerMember($guild, $member);

    $this->actingAs($member)
        ->postJson('/api/guilds/'.$guild->id.'/invites', [
            'email' => 'new-member@example.com',
        ])
        ->assertForbidden();

    expect(GuildInvite::query()->where('guild_id', $guild->id)->exists())->toBeFalse();
});

test('invite creation validates email payload', function (): void {
    $leader = User::factory()->create();
    $guild = createGuildInviteControllerGuild($leader);

    $this->actingAs($leader)
        ->postJson('/api/guilds/'.$guild->id.'/invites', [
            'email' => 'not-an-email',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('email');
});

test('invite creation rejects duplicate pending invites for the same email', function (): void {
    $leader = User::factory()->create();
    $guild = createGuildInviteControllerGuild($leader);

    $this->actingAs($leader)
        ->postJson('/api/guilds/'.$guild->id.'/invites', [
            'email' => 'pending@example.com',
        ])
        ->assertCreated();

    $this->actingAs($leader)
        ->postJson('/api/guilds/'.$guild->id.'/invites', [
            'email' => 'pending@example.com',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('email');

    expect(GuildInvite::query()
        ->where('guild_id', $guild->id)
        ->where('email', 'pending@example.com')
        ->count())->toBe(1);
});

test('invite token can be accepted without authentication', function (): void {
    $leader = User::factory()->create();
    $invitedUser = User::factory()->create(['email' => 'invited@example.com']);
    $guild = createGuildInviteControllerGuild($leader);

    $invite = GuildInvite::query()->create([
        'guild_id' => $guild->id,
        'invited_by' => $leader->id,
        'email' => 'invited@example.com',
        'token' => (string) Illuminate\Support\Str::uuid(),
        'expires_at' => now()->addHours(48),
    ]);

    $this->postJson('/api/guilds/invites/'.$invite->token.'/accept')
        ->assertOk()
        ->assertJsonPath('data.id', $guild->id)
        ->assertJsonPath('data.member_count', 2)
        ->assertJsonPath('data.current_user_role', 'member');

    expect($guild->users()->whereKey($invitedUser->id)->firstOrFail()->pivot->role)
        ->toBe('member')
        ->and($invite->refresh()->accepted_at)->not->toBeNull();
});

test('public invite acceptance rejects bad tokens', function (): void {
    $this->postJson('/api/guilds/invites/not-a-real-token/accept')
        ->assertUnprocessable()
        ->assertJsonValidationErrors('token');
});

test('public invite acceptance requires an existing user for the invited email', function (): void {
    $leader = User::factory()->create();
    $guild = createGuildInviteControllerGuild($leader);

    $invite = GuildInvite::query()->create([
        'guild_id' => $guild->id,
        'invited_by' => $leader->id,
        'email' => 'missing-user@example.com',
        'token' => (string) Illuminate\Support\Str::uuid(),
        'expires_at' => now()->addHours(48),
    ]);

    $this->postJson('/api/guilds/invites/'.$invite->token.'/accept')
        ->assertUnprocessable()
        ->assertJsonValidationErrors('email');
});
