<?php

use App\Events\LootDropped;
use App\Listeners\LogLootDrop;
use App\Listeners\UpdateUserLootStats;
use App\Providers\EventServiceProvider;

test('loot dropped listeners are explicitly registered in the event service provider', function (): void {
    $provider = new EventServiceProvider(app());

    expect($provider->listens())->toBe([
        LootDropped::class => [
            LogLootDrop::class,
            UpdateUserLootStats::class,
        ],
    ]);
});

test('event service provider is registered during application bootstrap', function (): void {
    $providers = require base_path('bootstrap/providers.php');

    expect($providers)->toContain(EventServiceProvider::class);
});
