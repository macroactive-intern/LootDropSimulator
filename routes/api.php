<?php

use App\Http\Controllers\Api\GuildController;
use App\Http\Controllers\Api\GuildInviteController;
use App\Http\Controllers\Api\GuildMemberController;
use App\Http\Controllers\Api\GuildTreasuryController;
use App\Http\Controllers\Api\LootController;
use Illuminate\Support\Facades\Route;

Route::middleware('throttle:60,1')->group(function (): void {
    Route::get('/loot-drops/global-stats', [LootController::class, 'globalStats']);
    Route::post('/guilds/invites/{token}/accept', [GuildInviteController::class, 'accept']);

    Route::middleware('auth:sanctum')->group(function (): void {
        Route::post('/loot-drop', [LootController::class, 'store']);
        Route::get('/loot-drops', [LootController::class, 'index']);
        Route::get('/loot-drops/stats', [LootController::class, 'stats']);

        Route::post('/admin/loot-grant', [LootController::class, 'grant'])
            ->middleware('admin');

        Route::get('/guilds', [GuildController::class, 'index']);
        Route::post('/guilds', [GuildController::class, 'store']);
        Route::get('/guilds/{guild}', [GuildController::class, 'show']);
        Route::put('/guilds/{guild}', [GuildController::class, 'update']);
        Route::delete('/guilds/{guild}', [GuildController::class, 'destroy']);
        Route::post('/guilds/{guild}/join', [GuildController::class, 'join']);
        Route::post('/guilds/{guild}/leave', [GuildController::class, 'leave']);
        Route::delete('/guilds/{guild}/members/{user}', [GuildMemberController::class, 'destroy']);
        Route::put('/guilds/{guild}/members/{user}', [GuildMemberController::class, 'updateRole']);
        Route::post('/guilds/{guild}/treasury/deposit', [GuildTreasuryController::class, 'deposit']);
        Route::post('/guilds/{guild}/treasury/withdraw', [GuildTreasuryController::class, 'withdraw']);
        Route::post('/guilds/{guild}/invites', [GuildInviteController::class, 'store']);
        Route::get('/guilds/{guild}/events', [GuildController::class, 'events']);
    });
});
