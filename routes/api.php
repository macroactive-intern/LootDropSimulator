<?php

use App\Http\Controllers\Api\LootController;
use Illuminate\Support\Facades\Route;

Route::middleware('throttle:60,1')->group(function (): void {
    Route::get('/loot-drops/global-stats', [LootController::class, 'globalStats']);

    Route::middleware('auth:sanctum')->group(function (): void {
        Route::post('/loot-drop', [LootController::class, 'store']);
        Route::get('/loot-drops', [LootController::class, 'index']);
        Route::get('/loot-drops/stats', [LootController::class, 'stats']);

        Route::post('/admin/loot-grant', [LootController::class, 'grant'])
            ->middleware('admin');
    });
});
