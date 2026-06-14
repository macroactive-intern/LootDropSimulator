<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\GuildMember;
use App\Models\Trade;
use App\Observers\GuildMemberObserver;
use App\Observers\TradeObserver;
use App\Support\GuildMemberAuditContext;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

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
        $this->validateRequiredEnv();

        GuildMember::observe(GuildMemberObserver::class);
        Trade::observe(TradeObserver::class);
    }

    private function validateRequiredEnv(): void
    {
        $required = [
            'app.key' => 'APP_KEY',
        ];

        $missing = array_filter($required, fn (string $key): bool => empty(config($key)), ARRAY_FILTER_USE_KEY);

        if ($missing !== []) {
            throw new RuntimeException(
                'Missing required environment variables: '.implode(', ', $missing).
                '. Copy .env.example to .env and run: php artisan key:generate'
            );
        }
    }
}
