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
]);
