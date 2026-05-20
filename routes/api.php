<?php

use App\Http\Controllers\Api\LootController;
use Illuminate\Support\Facades\Route;

Route::get('/loot-drops/global-stats', [LootController::class, 'globalStats']);

Route::middleware('auth')->group(function (): void {
    Route::post('/loot-drop', [LootController::class, 'store']);
    Route::get('/loot-drops', [LootController::class, 'index']);
    Route::get('/loot-drops/stats', [LootController::class, 'stats']);
});
