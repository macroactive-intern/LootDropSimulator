<?php

use Illuminate\Support\Facades\Route;

test('loot api routes are rate limited', function (string $method, string $uri): void {
    $route = Route::getRoutes()->match(
        request()->create($uri, $method)
    );

    expect($route->gatherMiddleware())->toContain('throttle:60,1');
})->with([
    ['POST', '/api/loot-drop'],
    ['GET', '/api/loot-drops'],
    ['GET', '/api/loot-drops/stats'],
    ['GET', '/api/loot-drops/global-stats'],
    ['POST', '/api/admin/loot-grant'],
    ['GET', '/api/inventory'],
]);

test('guild and trade api routes are registered with expected authentication', function (string $method, string $uri, bool $requiresAuth): void {
    $route = Route::getRoutes()->match(
        request()->create($uri, $method)
    );

    expect($route->gatherMiddleware())->toContain('throttle:60,1');

    if ($requiresAuth) {
        expect($route->gatherMiddleware())->toContain('auth:sanctum');
    } else {
        expect($route->gatherMiddleware())->not->toContain('auth:sanctum');
    }
})->with([
    ['GET', '/api/guilds', true],
    ['POST', '/api/guilds', true],
    ['GET', '/api/guilds/1', true],
    ['PUT', '/api/guilds/1', true],
    ['DELETE', '/api/guilds/1', true],
    ['POST', '/api/guilds/1/join', true],
    ['POST', '/api/guilds/1/leave', true],
    ['DELETE', '/api/guilds/1/members/2', true],
    ['PUT', '/api/guilds/1/members/2', true],
    ['POST', '/api/guilds/1/treasury/deposit', true],
    ['POST', '/api/guilds/1/treasury/withdraw', true],
    ['POST', '/api/guilds/1/invites', true],
    ['POST', '/api/guilds/invites/token-value/accept', false],
    ['GET', '/api/guilds/1/events', true],
    ['GET', '/api/inventory', true],
    ['GET', '/api/trades', true],
    ['POST', '/api/trades', true],
    ['GET', '/api/trades/1', true],
    ['POST', '/api/trades/1/accept', true],
    ['POST', '/api/trades/1/reject', true],
    ['POST', '/api/trades/1/cancel', true],
]);
