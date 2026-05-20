<?php

namespace App\Http\Controllers\Api;

use App\Events\LootDropped;
use App\Http\Controllers\Controller;
use App\Http\Resources\DroppedItemResource;
use App\Jobs\LootDropJob;
use App\Models\DroppedItem;
use App\Models\UserLootStat;
use App\Services\GuildBonusService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

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
            'consecutive_common_drops' => $stats?->consecutive_common_drops ?? 0,
            'last_drop_at' => $stats?->last_drop_at?->toISOString(),
        ]);
    }

    public function globalStats(): JsonResponse
    {
        $stats = DroppedItem::query()
            ->selectRaw(
                'COUNT(*) as total_drops, COALESCE(SUM(CASE WHEN rarity = ? THEN 1 ELSE 0 END), 0) as legendary_count',
                ['legendary']
            )
            ->first();

        return response()->json([
            'total_drops' => (int) $stats->total_drops,
            'legendary_count' => (int) $stats->legendary_count,
        ]);
    }

    public function grant(Request $request): JsonResponse
    {
        $items = collect(config('loot.items', []));
        $itemNames = $items->pluck('name')->all();

        $data = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'item_name' => ['required', 'string', Rule::in($itemNames)],
        ]);

        $item = $items->firstWhere('name', $data['item_name']);

        $droppedItem = DroppedItem::query()->create([
            'user_id' => $data['user_id'],
            'item_name' => $item['name'],
            'rarity' => $item['rarity'],
            'source' => 'admin_grant',
            'quantity' => 1,
        ]);

        event(new LootDropped($droppedItem));

        return (new DroppedItemResource($droppedItem))
            ->response()
            ->setStatusCode(201);
    }
}
