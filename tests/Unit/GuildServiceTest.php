<?php

use App\Models\Guild;
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
        ->and($reflection->hasMethod('acceptInvite'))->toBeTrue();
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

test('change role allows demoting a leader when another leader remains', function (): void {
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

    $service->changeRole($guild, $leader, $secondLeader, 'officer');

    expect($guild->users()->whereKey($secondLeader->id)->firstOrFail()->pivot->role)
        ->toBe('officer');
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
