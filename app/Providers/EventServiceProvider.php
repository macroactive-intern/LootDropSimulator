<?php

namespace App\Providers;

use App\Events\LootDropped;
use App\Listeners\LogLootDrop;
use App\Listeners\UpdateUserLootStats;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;

class EventServiceProvider extends ServiceProvider
{
    /**
     * Disable event discovery so the explicit listener map below is the single
     * registration source for LootDropped.
     */
    protected static $shouldDiscoverEvents = false;

    /**
     * The event to listener mappings for the application.
     *
     * @var array<class-string, array<int, class-string>>
     */
    protected $listen = [
        LootDropped::class => [
            LogLootDrop::class,
            UpdateUserLootStats::class,
        ],
    ];
}
