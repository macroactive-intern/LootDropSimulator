<?php

use App\Models\Guild;
use App\Models\GuildEvent;
use App\Models\GuildInvite;
use App\Models\User;
use App\Services\GuildService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

test('guild service exposes the guild workflow methods', function (): void {
    $reflection = new ReflectionClass(GuildService::class);

    expect($reflection->getConstructor()?->getParameters()[0]->getType()?->getName())
        ->toBe(Dispatcher::class)
        ->and($reflection->hasMethod('createGuild'))->toBeTrue()
        ->and($reflection->hasMethod('joinGuild'))->toBeTrue()
        ->and($reflection->hasMethod('leaveGuild'))->toBeTrue()
        ->and($reflection->hasMethod('kickMember'))->toBeTrue()
        ->and($reflection->hasMethod('changeRole'))->toBeTrue()
        ->and($reflection->hasMethod('depositTreasury'))->toBeTrue()
        ->and($reflection->hasMethod('withdrawTreasury'))->toBeTrue()
        ->and($reflection->hasMethod('createInvite'))->toBeTrue()
        ->and($reflection->hasMethod('acceptInviteToken'))->toBeTrue();
});

test('create guild creates the guild and attaches the creator as leader', function (): void {
    $creator = User::factory()->create();
    $service = app(GuildService::class);

    $guild = $service->createGuild($creator, [
        'name' => 'Loot Council',
        'description' => 'Organized dungeon runs.',
        'created_by' => User::factory()->create()->id,
        'is_open' => true,
    ]);

    expect($guild)->toBeInstanceOf(Guild::class)
        ->and($guild->created_by)->toBe($creator->id)
        ->and($guild->name)->toBe('Loot Council')
        ->and($guild->description)->toBe('Organized dungeon runs.')
        ->and($guild->is_open)->toBeTrue()
        ->and($guild->users()->whereKey($creator->id)->exists())->toBeTrue();

    $member = $guild->users()->whereKey($creator->id)->firstOrFail();

    expect($member->pivot->role)->toBe('leader')
        ->and($member->pivot->joined_at)->not->toBeNull()
        ->and($member->pivot->contributed_gold)->toBe(0);
});

test('join guild attaches a user as member when guild is open', function (): void {
    $creator = User::factory()->create();
    $user = User::factory()->create();
    $service = app(GuildService::class);
    $guild = $service->createGuild($creator, [
        'name' => 'Open Guild',
        'is_open' => true,
    ]);

    $service->joinGuild($guild, $user);

    $member = $guild->users()->whereKey($user->id)->firstOrFail();

    expect($member->pivot->role)->toBe('member')
        ->and($member->pivot->joined_at)->not->toBeNull()
        ->and($member->pivot->contributed_gold)->toBe(0);
});

test('join guild rejects closed guilds', function (): void {
    $creator = User::factory()->create();
    $user = User::factory()->create();
    $service = app(GuildService::class);
    $guild = $service->createGuild($creator, [
        'name' => 'Closed Guild',
        'is_open' => false,
    ]);

    expect(fn () => $service->joinGuild($guild, $user))
        ->toThrow(ValidationException::class, 'Only open guilds can be joined directly.');

    expect($guild->users()->whereKey($user->id)->exists())->toBeFalse();
});

test('join guild rejects existing members', function (): void {
    $creator = User::factory()->create();
    $user = User::factory()->create();
    $service = app(GuildService::class);
    $guild = $service->createGuild($creator, [
        'name' => 'Duplicate Guild',
        'is_open' => true,
    ]);

    $service->joinGuild($guild, $user);

    expect(fn () => $service->joinGuild($guild, $user))
        ->toThrow(ValidationException::class, 'User is already a member of this guild.');
});

test('join guild rejects users already in five guilds', function (): void {
    $creator = User::factory()->create();
    $user = User::factory()->create();
    $service = app(GuildService::class);

    for ($guildNumber = 1; $guildNumber <= 5; $guildNumber++) {
        $guild = $service->createGuild($creator, [
            'name' => 'Existing Guild '.$guildNumber,
            'is_open' => true,
        ]);

        $service->joinGuild($guild, $user);
    }

    $sixthGuild = $service->createGuild($creator, [
        'name' => 'Sixth Guild',
        'is_open' => true,
    ]);

    expect(fn () => $service->joinGuild($sixthGuild, $user))
        ->toThrow(ValidationException::class, 'User cannot belong to more than 5 guilds.');

    expect($sixthGuild->users()->whereKey($user->id)->exists())->toBeFalse();
});

test('join guild locks the user and membership rows before attaching', function (): void {
    $source = file_get_contents(app_path('Services/GuildService.php'));
    $joinMethodStart = strpos($source, 'public function joinGuild');
    $lockPosition = strpos($source, '->lockForUpdate()', $joinMethodStart);
    $membershipReadPosition = strpos($source, "DB::table('guild_user')", $joinMethodStart);
    $attachPosition = strpos($source, '->users()->attach', $joinMethodStart);

    expect($joinMethodStart)->not->toBeFalse()
        ->and($lockPosition)->not->toBeFalse()
        ->and($membershipReadPosition)->not->toBeFalse()
        ->and($attachPosition)->not->toBeFalse()
        ->and($lockPosition)->toBeLessThan($membershipReadPosition)
        ->and($membershipReadPosition)->toBeLessThan($attachPosition);
});

test('leave guild removes a non leader membership', function (): void {
    $creator = User::factory()->create();
    $member = User::factory()->create();
    $service = app(GuildService::class);
    $guild = $service->createGuild($creator, [
        'name' => 'Leave Guild',
        'is_open' => true,
    ]);
    $service->joinGuild($guild, $member);

    $service->leaveGuild($guild, $member);

    expect($guild->users()->whereKey($member->id)->exists())->toBeFalse()
        ->and($guild->users()->whereKey($creator->id)->exists())->toBeTrue();
});

test('leave guild rejects users who are not members', function (): void {
    $creator = User::factory()->create();
    $outsider = User::factory()->create();
    $service = app(GuildService::class);
    $guild = $service->createGuild($creator, [
        'name' => 'Outsider Guild',
        'is_open' => true,
    ]);

    expect(fn () => $service->leaveGuild($guild, $outsider))
        ->toThrow(ValidationException::class, 'User is not a member of this guild.');
});

test('leave guild prevents the last leader from leaving', function (): void {
    $creator = User::factory()->create();
    $service = app(GuildService::class);
    $guild = $service->createGuild($creator, [
        'name' => 'Last Leader Guild',
        'is_open' => true,
    ]);

    expect(fn () => $service->leaveGuild($guild, $creator))
        ->toThrow(ValidationException::class, 'A guild must always have at least one leader.');

    expect($guild->users()->whereKey($creator->id)->exists())->toBeTrue();
});

test('leave guild allows a leader to leave when another leader remains', function (): void {
    $creator = User::factory()->create();
    $secondLeader = User::factory()->create();
    $service = app(GuildService::class);
    $guild = $service->createGuild($creator, [
        'name' => 'Multi Leader Guild',
        'is_open' => true,
    ]);
    $guild->users()->attach($secondLeader->id, [
        'role' => 'leader',
        'joined_at' => now(),
    ]);

    $service->leaveGuild($guild, $creator);

    expect($guild->users()->whereKey($creator->id)->exists())->toBeFalse()
        ->and($guild->users()->whereKey($secondLeader->id)->exists())->toBeTrue();
});

test('guild member observer records join leave promote and demote events', function (): void {
    $leader = User::factory()->create();
    $member = User::factory()->create();
    $service = app(GuildService::class);
    $guild = $service->createGuild($leader, [
        'name' => 'Observed Member Guild',
        'is_open' => true,
    ]);

    $service->joinGuild($guild, $member);
    $service->changeRole($guild, $leader, $member, 'officer');
    $service->changeRole($guild, $leader, $member, 'member');
    $service->leaveGuild($guild, $member);

    $joinEvent = GuildEvent::query()
        ->where('guild_id', $guild->id)
        ->where('event_type', 'join')
        ->where('target_id', $member->id)
        ->firstOrFail();
    $promoteEvent = GuildEvent::query()
        ->where('guild_id', $guild->id)
        ->where('event_type', 'promote')
        ->where('target_id', $member->id)
        ->firstOrFail();
    $demoteEvent = GuildEvent::query()
        ->where('guild_id', $guild->id)
        ->where('event_type', 'demote')
        ->where('target_id', $member->id)
        ->firstOrFail();
    $leaveEvent = GuildEvent::query()
        ->where('guild_id', $guild->id)
        ->where('event_type', 'leave')
        ->where('target_id', $member->id)
        ->firstOrFail();

    expect($joinEvent->metadata['role'])->toBe('member')
        ->and($promoteEvent->metadata)->toBe([
            'from_role' => 'member',
            'to_role' => 'officer',
        ])
        ->and($demoteEvent->metadata)->toBe([
            'from_role' => 'officer',
            'to_role' => 'member',
        ])
        ->and($leaveEvent->metadata['role'])->toBe('member')
        ->and($leaveEvent->metadata['contributed_gold'])->toBe(0);
});

test('change role allows leaders to promote and demote valid transitions', function (): void {
    $leader = User::factory()->create();
    $member = User::factory()->create();
    $service = app(GuildService::class);
    $guild = $service->createGuild($leader, [
        'name' => 'Role Guild',
        'is_open' => true,
    ]);
    $service->joinGuild($guild, $member);

    $service->changeRole($guild, $leader, $member, 'officer');

    expect($guild->users()->whereKey($member->id)->firstOrFail()->pivot->role)
        ->toBe('officer');

    $service->changeRole($guild, $leader, $member, 'member');

    expect($guild->users()->whereKey($member->id)->firstOrFail()->pivot->role)
        ->toBe('member');
});

test('change role rejects officers modifying roles', function (): void {
    $leader = User::factory()->create();
    $officer = User::factory()->create();
    $member = User::factory()->create();
    $service = app(GuildService::class);
    $guild = $service->createGuild($leader, [
        'name' => 'Officer Cannot Promote Guild',
        'is_open' => true,
    ]);
    $service->joinGuild($guild, $officer);
    $service->joinGuild($guild, $member);
    $service->changeRole($guild, $leader, $officer, 'officer');

    expect(fn () => $service->changeRole($guild, $officer, $member, 'officer'))
        ->toThrow(ValidationException::class, 'Only guild leaders can change member roles.');
});

test('change role prevents invalid transitions', function (): void {
    $leader = User::factory()->create();
    $member = User::factory()->create();
    $service = app(GuildService::class);
    $guild = $service->createGuild($leader, [
        'name' => 'Invalid Transition Guild',
        'is_open' => true,
    ]);
    $service->joinGuild($guild, $member);

    expect(fn () => $service->changeRole($guild, $leader, $member, 'leader'))
        ->toThrow(ValidationException::class, 'Invalid guild role transition.');

    expect($guild->users()->whereKey($member->id)->firstOrFail()->pivot->role)
        ->toBe('member');
});

test('change role prevents no op transitions', function (): void {
    $leader = User::factory()->create();
    $member = User::factory()->create();
    $service = app(GuildService::class);
    $guild = $service->createGuild($leader, [
        'name' => 'No Op Guild',
        'is_open' => true,
    ]);
    $service->joinGuild($guild, $member);

    expect(fn () => $service->changeRole($guild, $leader, $member, 'member'))
        ->toThrow(ValidationException::class, 'Target user already has this role.');
});

test('change role prevents demoting the last leader', function (): void {
    $leader = User::factory()->create();
    $service = app(GuildService::class);
    $guild = $service->createGuild($leader, [
        'name' => 'Last Leader Demotion Guild',
        'is_open' => true,
    ]);

    expect(fn () => $service->changeRole($guild, $leader, $leader, 'officer'))
        ->toThrow(ValidationException::class, 'A guild must always have at least one leader.');

    expect($guild->users()->whereKey($leader->id)->firstOrFail()->pivot->role)
        ->toBe('leader');
});

test('change role allows demoting a leader directly to member when another leader remains', function (): void {
    $leader = User::factory()->create();
    $secondLeader = User::factory()->create();
    $service = app(GuildService::class);
    $guild = $service->createGuild($leader, [
        'name' => 'Leader Demotion Guild',
        'is_open' => true,
    ]);
    $guild->users()->attach($secondLeader->id, [
        'role' => 'leader',
        'joined_at' => now(),
    ]);

    $service->changeRole($guild, $leader, $secondLeader, 'member');

    expect($guild->users()->whereKey($secondLeader->id)->firstOrFail()->pivot->role)
        ->toBe('member');
});

test('kick member allows leaders to kick members and officers', function (): void {
    $leader = User::factory()->create();
    $officer = User::factory()->create();
    $member = User::factory()->create();
    $service = app(GuildService::class);
    $guild = $service->createGuild($leader, [
        'name' => 'Leader Kick Guild',
        'is_open' => true,
    ]);
    $service->joinGuild($guild, $officer);
    $service->joinGuild($guild, $member);
    $service->changeRole($guild, $leader, $officer, 'officer');

    $service->kickMember($guild, $leader, $member);
    $service->kickMember($guild, $leader, $officer);

    expect($guild->users()->whereKey($member->id)->exists())->toBeFalse()
        ->and($guild->users()->whereKey($officer->id)->exists())->toBeFalse()
        ->and($guild->users()->whereKey($leader->id)->exists())->toBeTrue();
});

test('kick member allows officers to kick members only', function (): void {
    $leader = User::factory()->create();
    $officer = User::factory()->create();
    $otherOfficer = User::factory()->create();
    $member = User::factory()->create();
    $service = app(GuildService::class);
    $guild = $service->createGuild($leader, [
        'name' => 'Officer Kick Guild',
        'is_open' => true,
    ]);
    $service->joinGuild($guild, $officer);
    $service->joinGuild($guild, $otherOfficer);
    $service->joinGuild($guild, $member);
    $service->changeRole($guild, $leader, $officer, 'officer');
    $service->changeRole($guild, $leader, $otherOfficer, 'officer');

    expect(fn () => $service->kickMember($guild, $officer, $otherOfficer))
        ->toThrow(ValidationException::class, 'Officers can only kick members.');

    $service->kickMember($guild, $officer, $member);

    expect($guild->users()->whereKey($member->id)->exists())->toBeFalse()
        ->and($guild->users()->whereKey($otherOfficer->id)->exists())->toBeTrue();
});

test('kick member rejects regular members as actors', function (): void {
    $leader = User::factory()->create();
    $member = User::factory()->create();
    $target = User::factory()->create();
    $service = app(GuildService::class);
    $guild = $service->createGuild($leader, [
        'name' => 'Member Kick Guild',
        'is_open' => true,
    ]);
    $service->joinGuild($guild, $member);
    $service->joinGuild($guild, $target);

    expect(fn () => $service->kickMember($guild, $member, $target))
        ->toThrow(ValidationException::class, 'Only guild leaders and officers can kick members.');
});

test('kick member rejects targets outside the guild', function (): void {
    $leader = User::factory()->create();
    $outsider = User::factory()->create();
    $service = app(GuildService::class);
    $guild = $service->createGuild($leader, [
        'name' => 'Outsider Kick Guild',
        'is_open' => true,
    ]);

    expect(fn () => $service->kickMember($guild, $leader, $outsider))
        ->toThrow(ValidationException::class, 'Target user is not a member of this guild.');
});

test('kick member prevents removing the last leader', function (): void {
    $leader = User::factory()->create();
    $service = app(GuildService::class);
    $guild = $service->createGuild($leader, [
        'name' => 'Last Leader Kick Guild',
        'is_open' => true,
    ]);

    expect(fn () => $service->kickMember($guild, $leader, $leader))
        ->toThrow(ValidationException::class, 'A guild must always have at least one leader.');

    expect($guild->users()->whereKey($leader->id)->exists())->toBeTrue();
});

test('kick member allows a leader to kick themselves when another leader remains', function (): void {
    $leader = User::factory()->create();
    $secondLeader = User::factory()->create();
    $service = app(GuildService::class);
    $guild = $service->createGuild($leader, [
        'name' => 'Self Kick Guild',
        'is_open' => true,
    ]);
    $guild->users()->attach($secondLeader->id, [
        'role' => 'leader',
        'joined_at' => now(),
    ]);

    $service->kickMember($guild, $leader, $leader);

    expect($guild->users()->whereKey($leader->id)->exists())->toBeFalse()
        ->and($guild->users()->whereKey($secondLeader->id)->exists())->toBeTrue();
});

test('deposit treasury increments guild balance and member contribution atomically', function (): void {
    $leader = User::factory()->create();
    $member = User::factory()->create();
    $service = app(GuildService::class);
    $guild = $service->createGuild($leader, [
        'name' => 'Deposit Guild',
        'is_open' => true,
    ]);
    $service->joinGuild($guild, $member);

    $service->depositTreasury($guild, $member, 150);
    $service->depositTreasury($guild, $member, 50);

    $contributor = $guild->users()->whereKey($member->id)->firstOrFail();

    expect($guild->refresh()->treasury_balance)->toBe(200)
        ->and($contributor->pivot->contributed_gold)->toBe(200);
});

test('deposit treasury rejects users outside the guild', function (): void {
    $leader = User::factory()->create();
    $outsider = User::factory()->create();
    $service = app(GuildService::class);
    $guild = $service->createGuild($leader, [
        'name' => 'Outsider Deposit Guild',
        'is_open' => true,
    ]);

    expect(fn () => $service->depositTreasury($guild, $outsider, 100))
        ->toThrow(ValidationException::class, 'User is not a member of this guild.');

    expect($guild->refresh()->treasury_balance)->toBe(0);
});

test('withdraw treasury subtracts balance and records audit metadata', function (): void {
    $leader = User::factory()->create();
    $service = app(GuildService::class);
    $guild = $service->createGuild($leader, [
        'name' => 'Withdraw Guild',
        'is_open' => true,
    ]);
    $service->depositTreasury($guild, $leader, 300);

    $service->withdrawTreasury($guild, $leader, 125, 'Buy raid supplies');

    $event = GuildEvent::query()
        ->where('guild_id', $guild->id)
        ->where('event_type', 'withdraw')
        ->firstOrFail();

    expect($guild->refresh()->treasury_balance)->toBe(175)
        ->and($event->actor_id)->toBe($leader->id)
        ->and($event->event_type)->toBe('withdraw')
        ->and($event->metadata)->toBe([
            'amount' => 125,
            'reason' => 'Buy raid supplies',
            'balance_before' => 300,
            'balance_after' => 175,
        ]);
});

test('withdraw treasury rejects non leaders', function (): void {
    $leader = User::factory()->create();
    $member = User::factory()->create();
    $service = app(GuildService::class);
    $guild = $service->createGuild($leader, [
        'name' => 'Member Withdraw Guild',
        'is_open' => true,
    ]);
    $service->joinGuild($guild, $member);
    $service->depositTreasury($guild, $leader, 100);

    expect(fn () => $service->withdrawTreasury($guild, $member, 25, 'Unauthorized snacks'))
        ->toThrow(ValidationException::class, 'Only guild leaders can withdraw from the treasury.');

    expect($guild->refresh()->treasury_balance)->toBe(100)
        ->and(GuildEvent::query()
            ->where('guild_id', $guild->id)
            ->where('event_type', 'withdraw')
            ->exists())->toBeFalse();
});

test('withdraw treasury prevents overdrafts', function (): void {
    $leader = User::factory()->create();
    $service = app(GuildService::class);
    $guild = $service->createGuild($leader, [
        'name' => 'Overdraft Guild',
        'is_open' => true,
    ]);
    $service->depositTreasury($guild, $leader, 50);

    expect(fn () => $service->withdrawTreasury($guild, $leader, 75, 'Too expensive'))
        ->toThrow(ValidationException::class, 'Guild treasury has insufficient funds.');

    expect($guild->refresh()->treasury_balance)->toBe(50)
        ->and(GuildEvent::query()
            ->where('guild_id', $guild->id)
            ->where('event_type', 'withdraw')
            ->exists())->toBeFalse();
});

test('create invite stores inviter token and forty eight hour expiry', function (): void {
    $leader = User::factory()->create();
    $service = app(GuildService::class);
    $guild = $service->createGuild($leader, [
        'name' => 'Invite Guild',
        'is_open' => true,
    ]);
    $now = now()->startOfSecond();

    $this->travelTo($now);

    $invite = $service->createInvite($guild, $leader, [
        'email' => 'new-member@example.com',
    ]);

    expect($invite)->toBeInstanceOf(GuildInvite::class)
        ->and($invite->guild_id)->toBe($guild->id)
        ->and($invite->invited_by)->toBe($leader->id)
        ->and($invite->email)->toBe('new-member@example.com')
        ->and($invite->token)->toBeString()
        ->and($invite->token)->not->toBe('')
        ->and($invite->accepted_at)->toBeNull()
        ->and($invite->expires_at->equalTo($now->copy()->addHours(48)))->toBeTrue()
        ->and(GuildEvent::query()
            ->where('guild_id', $guild->id)
            ->where('actor_id', $leader->id)
            ->where('event_type', 'invite_sent')
            ->whereJsonContains('metadata->email', 'new-member@example.com')
            ->exists())->toBeTrue();
});

test('create invite rejects duplicate pending invites for the same email', function (): void {
    $leader = User::factory()->create();
    $service = app(GuildService::class);
    $guild = $service->createGuild($leader, [
        'name' => 'Duplicate Pending Invite Guild',
        'is_open' => false,
    ]);

    $service->createInvite($guild, $leader, [
        'email' => 'pending@example.com',
    ]);

    expect(fn () => $service->createInvite($guild, $leader, [
        'email' => 'pending@example.com',
    ]))->toThrow(ValidationException::class, 'A pending invite already exists for this email.');

    expect(GuildInvite::query()
        ->where('guild_id', $guild->id)
        ->where('email', 'pending@example.com')
        ->count())->toBe(1);
});

test('create invite rejects existing guild members by email', function (): void {
    $leader = User::factory()->create();
    $member = User::factory()->create(['email' => 'member@example.com']);
    $service = app(GuildService::class);
    $guild = $service->createGuild($leader, [
        'name' => 'Existing Member Invite Guild',
        'is_open' => true,
    ]);
    $service->joinGuild($guild, $member);

    expect(fn () => $service->createInvite($guild, $leader, [
        'email' => 'member@example.com',
    ]))->toThrow(ValidationException::class, 'User is already a member of this guild.');

    expect(GuildInvite::query()->where('guild_id', $guild->id)->exists())->toBeFalse();
});

test('accept invite adds the user as a member and marks the invite accepted', function (): void {
    $leader = User::factory()->create();
    $user = User::factory()->create(['email' => 'accepted@example.com']);
    $service = app(GuildService::class);
    $guild = $service->createGuild($leader, [
        'name' => 'Accept Invite Guild',
        'is_open' => false,
    ]);
    $invite = $service->createInvite($guild, $leader, [
        'email' => 'accepted@example.com',
    ]);
    $now = now()->startOfSecond();

    $this->travelTo($now);

    $service->acceptInviteToken($invite->token);

    $member = $guild->users()->whereKey($user->id)->firstOrFail();
    $joinEvent = GuildEvent::query()
        ->where('guild_id', $guild->id)
        ->where('event_type', 'join')
        ->where('target_id', $user->id)
        ->firstOrFail();
    $acceptedEvent = GuildEvent::query()
        ->where('guild_id', $guild->id)
        ->where('event_type', 'invite_accepted')
        ->where('target_id', $user->id)
        ->firstOrFail();

    expect($member->pivot->role)->toBe('member')
        ->and($member->pivot->joined_at)->not->toBeNull()
        ->and($member->pivot->contributed_gold)->toBe(0)
        ->and($invite->refresh()->accepted_at->equalTo($now))->toBeTrue()
        ->and($joinEvent->actor_id)->toBe($user->id)
        ->and($joinEvent->target_id)->toBe($user->id)
        ->and($joinEvent->metadata)->toBe([
            'role' => 'member',
            'joined_at' => $now->toISOString(),
        ])
        ->and($acceptedEvent->actor_id)->toBe($user->id)
        ->and($acceptedEvent->metadata)->toBe([
            'invite_id' => $invite->id,
            'email' => 'accepted@example.com',
            'invited_by' => $leader->id,
            'accepted_at' => $now->toISOString(),
        ]);
});

test('accept invite rejects missing invite tokens', function (): void {
    $leader = User::factory()->create();
    $service = app(GuildService::class);
    $guild = $service->createGuild($leader, [
        'name' => 'Missing Invite Guild',
        'is_open' => false,
    ]);
    $invite = $service->createInvite($guild, $leader, [
        'email' => 'missing-token@example.com',
    ]);
    $invite->delete();

    expect(fn () => $service->acceptInviteToken($invite->token))
        ->toThrow(ValidationException::class, 'Guild invite does not exist.');
});

test('accept invite rejects expired invites', function (): void {
    $leader = User::factory()->create();
    $user = User::factory()->create(['email' => 'expired@example.com']);
    $service = app(GuildService::class);
    $guild = $service->createGuild($leader, [
        'name' => 'Expired Invite Guild',
        'is_open' => false,
    ]);
    $invite = $service->createInvite($guild, $leader, [
        'email' => 'expired@example.com',
    ]);
    $invite->forceFill(['expires_at' => now()->subMinute()])->save();

    expect(fn () => $service->acceptInviteToken($invite->token))
        ->toThrow(ValidationException::class, 'Guild invite has expired.');

    expect($guild->users()->whereKey($user->id)->exists())->toBeFalse()
        ->and($invite->refresh()->accepted_at)->toBeNull();
});

test('accept invite rejects already accepted invites', function (): void {
    $leader = User::factory()->create();
    $user = User::factory()->create(['email' => 'already-accepted@example.com']);
    $service = app(GuildService::class);
    $guild = $service->createGuild($leader, [
        'name' => 'Already Accepted Invite Guild',
        'is_open' => false,
    ]);
    $invite = $service->createInvite($guild, $leader, [
        'email' => 'already-accepted@example.com',
    ]);
    $service->acceptInviteToken($invite->token);

    expect(fn () => $service->acceptInviteToken($invite->refresh()->token))
        ->toThrow(ValidationException::class, 'Guild invite has already been accepted.');
});

test('accept invite rejects users already in the guild', function (): void {
    $leader = User::factory()->create();
    $member = User::factory()->create(['email' => 'member-in-guild@example.com']);
    $service = app(GuildService::class);
    $guild = $service->createGuild($leader, [
        'name' => 'Already Member Accept Guild',
        'is_open' => true,
    ]);
    $service->joinGuild($guild, $member);
    $invite = GuildInvite::query()->create([
        'guild_id' => $guild->id,
        'invited_by' => $leader->id,
        'email' => 'member-in-guild@example.com',
        'token' => (string) Illuminate\Support\Str::uuid(),
        'expires_at' => now()->addHours(48),
    ]);

    expect(fn () => $service->acceptInviteToken($invite->token))
        ->toThrow(ValidationException::class, 'User is already a member of this guild.');

    expect($invite->refresh()->accepted_at)->toBeNull();
});

test('accept invite rejects users already in five guilds', function (): void {
    $leader = User::factory()->create();
    $user = User::factory()->create(['email' => 'five-guilds@example.com']);
    $service = app(GuildService::class);

    for ($guildNumber = 1; $guildNumber <= 5; $guildNumber++) {
        $guild = $service->createGuild($leader, [
            'name' => 'Accepted Existing Guild '.$guildNumber,
            'is_open' => true,
        ]);

        $service->joinGuild($guild, $user);
    }

    $inviteGuild = $service->createGuild($leader, [
        'name' => 'Sixth Invite Guild',
        'is_open' => false,
    ]);
    $invite = $service->createInvite($inviteGuild, $leader, [
        'email' => 'five-guilds@example.com',
    ]);

    expect(fn () => $service->acceptInviteToken($invite->token))
        ->toThrow(ValidationException::class, 'User cannot belong to more than 5 guilds.');

    expect($inviteGuild->users()->whereKey($user->id)->exists())->toBeFalse()
        ->and($invite->refresh()->accepted_at)->toBeNull();
});
