<?php

use App\Services\GuildService;
use Illuminate\Contracts\Events\Dispatcher;

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
