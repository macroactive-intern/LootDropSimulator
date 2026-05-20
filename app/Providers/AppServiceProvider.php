<?php

namespace App\Providers;

use App\Events\LootDropped;
use App\Listeners\LogLootDrop;
use App\Listeners\UpdateUserLootStats;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Event::listen(LootDropped::class, LogLootDrop::class);
        Event::listen(LootDropped::class, UpdateUserLootStats::class);
    }
}
