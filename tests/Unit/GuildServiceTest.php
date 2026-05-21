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
