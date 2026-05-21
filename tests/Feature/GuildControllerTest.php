<?php

use App\Models\Guild;
use App\Models\GuildEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function createGuildControllerGuild(User $creator, array $attributes = []): Guild
{
    $guild = Guild::query()->create(array_merge([
        'name' => 'Controller Guild',
        'description' => 'Controller managed guild.',
        'created_by' => $creator->id,
        'is_open' => true,
    ], $attributes));

    $guild->users()->attach($creator->id, [
        'role' => 'leader',
        'joined_at' => now(),
    ]);

    return $guild;
}

function attachGuildControllerMember(Guild $guild, User $user, string $role = 'member'): void
{
    $guild->users()->attach($user->id, [
        'role' => $role,
        'joined_at' => now(),
    ]);
}

test('guild endpoints require authentication', function (): void {
    $this->getJson('/api/guilds')->assertUnauthorized();
});

test('authenticated users can list guilds', function (): void {
    $creator = User::factory()->create();
    $guild = createGuildControllerGuild($creator);

    $this->actingAs($creator)
        ->getJson('/api/guilds')
        ->assertOk()
        ->assertJsonPath('data.0.id', $guild->id)
        ->assertJsonPath('data.0.member_count', 1)
        ->assertJsonStructure([
            'data' => [
                [
                    'id',
                    'name',
                    'description',
                    'treasury_balance',
                    'is_open',
                    'member_count',
                    'current_user_role',
                ],
            ],
            'links',
            'meta',
        ]);
});

test('authenticated users can create guilds', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->postJson('/api/guilds', [
            'name' => 'New Guild',
            'description' => 'Fresh guild.',
            'is_open' => true,
        ])
        ->assertCreated()
        ->assertJsonPath('data.name', 'New Guild')
        ->assertJsonPath('data.current_user_role', 'leader');

    $guild = Guild::query()->where('name', 'New Guild')->firstOrFail();

    expect($guild->created_by)->toBe($user->id)
        ->and($guild->users()->whereKey($user->id)->firstOrFail()->pivot->role)->toBe('leader');
});

test('members can show guilds through a resource', function (): void {
    $leader = User::factory()->create();
    $member = User::factory()->create();
    $guild = createGuildControllerGuild($leader);
    attachGuildControllerMember($guild, $member);

    $this->actingAs($member)
        ->getJson('/api/guilds/'.$guild->id)
        ->assertOk()
        ->assertJsonPath('data.id', $guild->id)
        ->assertJsonPath('data.current_user_role', 'member');
});

test('leaders can update guilds', function (): void {
    $leader = User::factory()->create();
    $guild = createGuildControllerGuild($leader);

    $this->actingAs($leader)
        ->putJson('/api/guilds/'.$guild->id, [
            'name' => 'Updated Guild',
            'description' => null,
            'is_open' => false,
        ])
        ->assertOk()
        ->assertJsonPath('data.name', 'Updated Guild')
        ->assertJsonPath('data.description', null)
        ->assertJsonPath('data.is_open', false);
});

test('non leaders cannot update guilds', function (): void {
    $leader = User::factory()->create();
    $member = User::factory()->create();
    $guild = createGuildControllerGuild($leader);
    attachGuildControllerMember($guild, $member);

    $this->actingAs($member)
        ->putJson('/api/guilds/'.$guild->id, [
            'name' => 'Unauthorized Rename',
        ])
        ->assertForbidden();
});

test('creator leaders can destroy guilds', function (): void {
    $leader = User::factory()->create();
    $guild = createGuildControllerGuild($leader);

    $this->actingAs($leader)
        ->deleteJson('/api/guilds/'.$guild->id)
        ->assertNoContent();

    expect(Guild::query()->whereKey($guild->id)->exists())->toBeFalse();
});

test('users can join open guilds and leave guilds', function (): void {
    $leader = User::factory()->create();
    $user = User::factory()->create();
    $guild = createGuildControllerGuild($leader, ['is_open' => true]);

    $this->actingAs($user)
        ->postJson('/api/guilds/'.$guild->id.'/join')
        ->assertOk()
        ->assertJsonPath('data.current_user_role', 'member');

    expect($guild->users()->whereKey($user->id)->exists())->toBeTrue();

    $this->actingAs($user)
        ->postJson('/api/guilds/'.$guild->id.'/leave')
        ->assertNoContent();

    expect($guild->users()->whereKey($user->id)->exists())->toBeFalse();
});

test('officers can view guild events', function (): void {
    $leader = User::factory()->create();
    $officer = User::factory()->create();
    $guild = createGuildControllerGuild($leader);
    attachGuildControllerMember($guild, $officer, 'officer');

    GuildEvent::query()->create([
        'guild_id' => $guild->id,
        'actor_id' => $leader->id,
        'target_id' => $officer->id,
        'event_type' => 'promote',
        'metadata' => [
            'from_role' => 'member',
            'to_role' => 'officer',
        ],
    ]);

    $this->actingAs($officer)
        ->getJson('/api/guilds/'.$guild->id.'/events')
        ->assertOk()
        ->assertJsonPath('data.0.event_type', 'promote')
        ->assertJsonPath('data.0.metadata.from_role', 'member')
        ->assertJsonStructure([
            'data' => [
                [
                    'id',
                    'event_type',
                    'actor_id',
                    'target_id',
                    'metadata',
                    'created_at',
                ],
            ],
            'links',
            'meta',
        ]);
});

test('members cannot view guild events', function (): void {
    $leader = User::factory()->create();
    $member = User::factory()->create();
    $guild = createGuildControllerGuild($leader);
    attachGuildControllerMember($guild, $member);

    $this->actingAs($member)
        ->getJson('/api/guilds/'.$guild->id.'/events')
        ->assertForbidden();
});
