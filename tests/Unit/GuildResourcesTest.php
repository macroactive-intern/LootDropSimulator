<?php

use App\Http\Resources\GuildMemberResource;
use App\Http\Resources\GuildResource;
use App\Models\Guild;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;

uses(RefreshDatabase::class);

test('guild resource formats guild summary fields with current user role', function (): void {
    $leader = User::factory()->create();
    $member = User::factory()->create();
    $guild = Guild::query()->create([
        'name' => 'Resource Guild',
        'description' => 'Resource shaped guild.',
        'created_by' => $leader->id,
        'treasury_balance' => 750,
        'is_open' => true,
    ]);
    $guild->users()->attach($leader->id, [
        'role' => 'leader',
        'joined_at' => now(),
    ]);
    $guild->users()->attach($member->id, [
        'role' => 'member',
        'joined_at' => now(),
    ]);
    $request = Request::create('/api/guilds/'.$guild->id);
    $request->setUserResolver(fn (): User => $member);

    $resource = (new GuildResource($guild->load('users')))->toArray($request);

    expect(array_keys($resource))->toBe([
        'id',
        'name',
        'description',
        'treasury_balance',
        'is_open',
        'member_count',
        'current_user_role',
    ])
        ->and($resource['id'])->toBe($guild->id)
        ->and($resource['name'])->toBe('Resource Guild')
        ->and($resource['description'])->toBe('Resource shaped guild.')
        ->and($resource['treasury_balance'])->toBe(750)
        ->and($resource['is_open'])->toBeTrue()
        ->and($resource['member_count'])->toBe(2)
        ->and($resource['current_user_role'])->toBe('member');
});

test('guild member resource formats user and pivot membership fields', function (): void {
    $leader = User::factory()->create();
    $member = User::factory()->create(['name' => 'Nate']);
    $guild = Guild::query()->create([
        'name' => 'Member Resource Guild',
        'created_by' => $leader->id,
    ]);
    $joinedAt = now()->startOfSecond();

    $guild->users()->attach($member->id, [
        'role' => 'officer',
        'joined_at' => $joinedAt,
        'contributed_gold' => 125,
    ]);

    $resourceMember = $guild->users()->whereKey($member->id)->firstOrFail();
    $resource = (new GuildMemberResource($resourceMember))->toArray(new Request());

    expect(array_keys($resource))->toBe([
        'id',
        'name',
        'role',
        'joined_at',
        'contributed_gold',
    ])
        ->and($resource['id'])->toBe($member->id)
        ->and($resource['name'])->toBe('Nate')
        ->and($resource['role'])->toBe('officer')
        ->and($resource['joined_at'])->toBe($joinedAt->toISOString())
        ->and($resource['contributed_gold'])->toBe(125);
});
