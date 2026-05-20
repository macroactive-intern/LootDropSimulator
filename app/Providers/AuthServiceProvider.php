<?php

namespace App\Providers;

use App\Models\Guild;
use App\Policies\GuildPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AuthServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap any authentication and authorization services.
     */
    public function boot(): void
    {
        Gate::policy(Guild::class, GuildPolicy::class);
    }
}
