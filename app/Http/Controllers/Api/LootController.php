<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\DroppedItemResource;
use App\Jobs\LootDropJob;
use App\Models\DroppedItem;
use App\Models\UserLootStat;
use App\Services\GuildBonusService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LootController extends Controller
{
    public function store(Request $request, GuildBonusService $guildBonusService): JsonResponse
    {
        $data = $request->validate([
            'source' => ['required', 'string', 'max:255'],
        ]);

        $userId = $request->user()->id;
        $multiplier = $guildBonusService->getMultiplierForUser($userId);

        LootDropJob::dispatch(
            $userId,
            $data['source'],
            $multiplier,
        );

        return response()->json([
            'success' => true,
            'message' => 'Loot drop queued.',
        ], 202);
    }

    public function index(Request $request)
    {
        $data = $request->validate([
            'rarity' => ['sometimes', 'string', 'max:255'],
        ]);

        $droppedItems = DroppedItem::query()
            ->where('user_id', $request->user()->id)
            ->when(
                $data['rarity'] ?? null,
                fn ($query, string $rarity) => $query->where('rarity', $rarity)
            )
            ->latest()
            ->paginate(15);

        return DroppedItemResource::collection($droppedItems);
    }

    public function stats(Request $request): JsonResponse
    {
        $stats = UserLootStat::query()
            ->where('user_id', $request->user()->id)
            ->first();

        return response()->json([
            'user_id' => $request->user()->id,
            'total_drops' => $stats?->total_drops ?? 0,
            'legendary_count' => $stats?->legendary_count ?? 0,
            'last_drop_at' => $stats?->last_drop_at?->toISOString(),
        ]);
    }

    public function globalStats(): JsonResponse
    {
        return response()->json([
            'total_drops' => DroppedItem::query()->count(),
            'legendary_count' => DroppedItem::query()
                ->where('rarity', 'legendary')
                ->count(),
        ]);
    }
}
