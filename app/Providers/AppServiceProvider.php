<?php

namespace App\Providers;

use App\Models\GuildMember;
use App\Observers\GuildMemberObserver;
use App\Support\GuildMemberAuditContext;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(GuildMemberAuditContext::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        GuildMember::observe(GuildMemberObserver::class);
    }
}
