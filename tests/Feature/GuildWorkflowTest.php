<?php

use App\Models\Guild;
use App\Models\GuildInvite;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('users can create guilds and become leaders', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->postJson('/api/guilds', [
            'name' => 'Feature Test Guild',
            'description' => 'Created from a feature workflow.',
            'is_open' => true,
        ])
        ->assertCreated()
        ->assertJsonPath('data.name', 'Feature Test Guild')
        ->assertJsonPath('data.current_user_role', 'leader');

    $guild = Guild::query()->where('name', 'Feature Test Guild')->firstOrFail();

    expect($guild->created_by)->toBe($user->id)
        ->and($guild->users()->whereKey($user->id)->firstOrFail()->pivot->role)->toBe('leader');
});

test('users can join open guilds', function (): void {
    $leader = User::factory()->create();
    $user = User::factory()->create();
    $guild = createTestGuild($leader, ['is_open' => true]);

    $this->actingAs($user)
        ->postJson('/api/guilds/'.$guild->id.'/join')
        ->assertOk()
        ->assertJsonPath('data.current_user_role', 'member');

    expect($guild->users()->whereKey($user->id)->firstOrFail()->pivot->role)
        ->toBe('member');
});

test('users can leave guilds', function (): void {
    $leader = User::factory()->create();
    $member = User::factory()->create();
    $guild = createTestGuild($leader);
    attachTestGuildMember($guild, $member);

    $this->actingAs($member)
        ->postJson('/api/guilds/'.$guild->id.'/leave')
        ->assertNoContent();

    expect($guild->users()->whereKey($member->id)->exists())->toBeFalse();
});

test('users cannot join more than five guilds', function (): void {
    $user = User::factory()->create();

    for ($guildNumber = 1; $guildNumber <= 5; $guildNumber++) {
        $leader = User::factory()->create();
        $guild = createTestGuild($leader, [
            'name' => 'Membership Limit Guild '.$guildNumber,
        ]);

        attachTestGuildMember($guild, $user);
    }

    $sixthLeader = User::factory()->create();
    $sixthGuild = createTestGuild($sixthLeader, [
        'name' => 'Sixth Membership Limit Guild',
    ]);

    $this->actingAs($user)
        ->postJson('/api/guilds/'.$sixthGuild->id.'/join')
        ->assertUnprocessable()
        ->assertJsonValidationErrors('guild');

    expect($sixthGuild->users()->whereKey($user->id)->exists())->toBeFalse();
});

test('join workflow locks membership checks before attaching users', function (): void {
    $source = file_get_contents(app_path('Services/GuildService.php'));
    $joinMethodStart = strpos($source, 'public function joinGuild');
    $userLockPosition = strpos($source, 'User::query()', $joinMethodStart);
    $membershipLockPosition = strpos($source, "DB::table('guild_user')", $joinMethodStart);
    $attachPosition = strpos($source, '->users()->attach', $joinMethodStart);

    expect($joinMethodStart)->not->toBeFalse()
        ->and($userLockPosition)->not->toBeFalse()
        ->and($membershipLockPosition)->not->toBeFalse()
        ->and($attachPosition)->not->toBeFalse()
        ->and($userLockPosition)->toBeLessThan($membershipLockPosition)
        ->and($membershipLockPosition)->toBeLessThan($attachPosition);
});

test('users can accept guild invites without authentication', function (): void {
    $leader = User::factory()->create();
    $invitedUser = User::factory()->create(['email' => 'workflow-invite@example.com']);
    $guild = createTestGuild($leader, ['is_open' => false]);
    $invite = GuildInvite::query()->create([
        'guild_id' => $guild->id,
        'invited_by' => $leader->id,
        'email' => 'workflow-invite@example.com',
        'token' => (string) str()->uuid(),
        'expires_at' => now()->addHours(48),
    ]);

    $this->postJson('/api/guilds/invites/'.$invite->token.'/accept')
        ->assertOk()
        ->assertJsonPath('data.id', $guild->id)
        ->assertJsonPath('data.member_count', 2);

    expect($guild->users()->whereKey($invitedUser->id)->firstOrFail()->pivot->role)
        ->toBe('member')
        ->and($invite->refresh()->accepted_at)->not->toBeNull();
});

test('leaders can change member roles', function (): void {
    $leader = User::factory()->create();
    $member = User::factory()->create();
    $guild = createTestGuild($leader);
    attachTestGuildMember($guild, $member);

    $this->actingAs($leader)
        ->putJson('/api/guilds/'.$guild->id.'/members/'.$member->id, [
            'role' => 'officer',
        ])
        ->assertOk()
        ->assertJsonPath('data.role', 'officer');

    expect($guild->users()->whereKey($member->id)->firstOrFail()->pivot->role)
        ->toBe('officer');
});

test('authorized guild members can kick members', function (): void {
    $leader = User::factory()->create();
    $member = User::factory()->create();
    $guild = createTestGuild($leader);
    attachTestGuildMember($guild, $member);

    $this->actingAs($leader)
        ->deleteJson('/api/guilds/'.$guild->id.'/members/'.$member->id)
        ->assertNoContent();

    expect($guild->users()->whereKey($member->id)->exists())->toBeFalse();
});
