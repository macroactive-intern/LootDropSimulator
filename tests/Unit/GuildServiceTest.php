<?php

use App\Models\Guild;
use App\Models\User;
use App\Services\GuildService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Contracts\Events\Dispatcher;

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
