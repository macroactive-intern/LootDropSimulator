<?php

use App\Models\Guild;
use App\Models\User;
use App\Providers\AuthServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;

uses(RefreshDatabase::class);

test('auth service provider is registered for guild policies', function (): void {
    expect(require base_path('bootstrap/providers.php'))->toContain(AuthServiceProvider::class);
});

function createGuildPolicyGuild(User $creator): Guild
{
    return Guild::query()->create([
        'name' => 'Policy Guild',
        'created_by' => $creator->id,
    ]);
}

function attachGuildPolicyMember(Guild $guild, User $user, string $role): void
{
    $guild->users()->attach($user->id, [
        'role' => $role,
        'joined_at' => now(),
    ]);
}

test('guild leader can manage the guild except deleting when not creator', function (): void {
    $creator = User::factory()->create();
    $leader = User::factory()->create();
    $officer = User::factory()->create();
    $member = User::factory()->create();
    $guild = createGuildPolicyGuild($creator);

    attachGuildPolicyMember($guild, $leader, 'leader');
    attachGuildPolicyMember($guild, $officer, 'officer');
    attachGuildPolicyMember($guild, $member, 'member');

    expect(Gate::forUser($leader)->allows('update', $guild))->toBeTrue()
        ->and(Gate::forUser($leader)->allows('invite', $guild))->toBeTrue()
        ->and(Gate::forUser($leader)->allows('kick', [$guild, $officer]))->toBeTrue()
        ->and(Gate::forUser($leader)->allows('promote', [$guild, $member]))->toBeTrue()
        ->and(Gate::forUser($leader)->allows('demote', [$guild, $officer]))->toBeTrue()
        ->and(Gate::forUser($leader)->allows('changeRole', [$guild, $member]))->toBeTrue()
        ->and(Gate::forUser($leader)->allows('changeRole', [$guild, User::factory()->create()]))->toBeFalse()
        ->and(Gate::forUser($leader)->allows('deposit', $guild))->toBeTrue()
        ->and(Gate::forUser($leader)->allows('withdraw', $guild))->toBeTrue()
        ->and(Gate::forUser($leader)->allows('viewEvents', $guild))->toBeTrue()
        ->and(Gate::forUser($leader)->allows('delete', $guild))->toBeFalse();
});

test('guild creator can delete only when they are the leader', function (): void {
    $creator = User::factory()->create();
    $guild = createGuildPolicyGuild($creator);

    attachGuildPolicyMember($guild, $creator, 'leader');

    expect(Gate::forUser($creator)->allows('delete', $guild))->toBeTrue();
});

test('guild officers can invite kick members deposit and view events only', function (): void {
    $creator = User::factory()->create();
    $officer = User::factory()->create();
    $otherOfficer = User::factory()->create();
    $member = User::factory()->create();
    $guild = createGuildPolicyGuild($creator);

    attachGuildPolicyMember($guild, $officer, 'officer');
    attachGuildPolicyMember($guild, $otherOfficer, 'officer');
    attachGuildPolicyMember($guild, $member, 'member');

    expect(Gate::forUser($officer)->allows('invite', $guild))->toBeTrue()
        ->and(Gate::forUser($officer)->allows('kick', [$guild, $member]))->toBeTrue()
        ->and(Gate::forUser($officer)->allows('deposit', $guild))->toBeTrue()
        ->and(Gate::forUser($officer)->allows('viewEvents', $guild))->toBeTrue()
        ->and(Gate::forUser($officer)->allows('kick', [$guild, $otherOfficer]))->toBeFalse()
        ->and(Gate::forUser($officer)->allows('update', $guild))->toBeFalse()
        ->and(Gate::forUser($officer)->allows('delete', $guild))->toBeFalse()
        ->and(Gate::forUser($officer)->allows('promote', [$guild, $member]))->toBeFalse()
        ->and(Gate::forUser($officer)->allows('demote', [$guild, $otherOfficer]))->toBeFalse()
        ->and(Gate::forUser($officer)->allows('changeRole', [$guild, $member]))->toBeFalse()
        ->and(Gate::forUser($officer)->allows('withdraw', $guild))->toBeFalse();
});

test('guild members can only deposit', function (): void {
    $creator = User::factory()->create();
    $member = User::factory()->create();
    $otherMember = User::factory()->create();
    $guild = createGuildPolicyGuild($creator);

    attachGuildPolicyMember($guild, $member, 'member');
    attachGuildPolicyMember($guild, $otherMember, 'member');

    expect(Gate::forUser($member)->allows('deposit', $guild))->toBeTrue()
        ->and(Gate::forUser($member)->allows('invite', $guild))->toBeFalse()
        ->and(Gate::forUser($member)->allows('kick', [$guild, $otherMember]))->toBeFalse()
        ->and(Gate::forUser($member)->allows('promote', [$guild, $otherMember]))->toBeFalse()
        ->and(Gate::forUser($member)->allows('demote', [$guild, $otherMember]))->toBeFalse()
        ->and(Gate::forUser($member)->allows('changeRole', [$guild, $otherMember]))->toBeFalse()
        ->and(Gate::forUser($member)->allows('withdraw', $guild))->toBeFalse()
        ->and(Gate::forUser($member)->allows('viewEvents', $guild))->toBeFalse();
});

test('non members cannot use guild permissions', function (): void {
    $creator = User::factory()->create();
    $outsider = User::factory()->create();
    $member = User::factory()->create();
    $guild = createGuildPolicyGuild($creator);

    attachGuildPolicyMember($guild, $member, 'member');

    expect(Gate::forUser($outsider)->allows('update', $guild))->toBeFalse()
        ->and(Gate::forUser($outsider)->allows('invite', $guild))->toBeFalse()
        ->and(Gate::forUser($outsider)->allows('kick', [$guild, $member]))->toBeFalse()
        ->and(Gate::forUser($outsider)->allows('changeRole', [$guild, $member]))->toBeFalse()
        ->and(Gate::forUser($outsider)->allows('deposit', $guild))->toBeFalse()
        ->and(Gate::forUser($outsider)->allows('withdraw', $guild))->toBeFalse()
        ->and(Gate::forUser($outsider)->allows('viewEvents', $guild))->toBeFalse();
});
