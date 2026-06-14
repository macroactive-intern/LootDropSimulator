<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Events\LootDropped;
use App\Http\Controllers\Controller;
use App\Http\Resources\DroppedItemResource;
use App\Http\Resources\GlobalLootStatResource;
use App\Http\Resources\UserLootStatResource;
use App\Jobs\LootDropJob;
use App\Models\DroppedItem;
use App\Models\UserLootStat;
use App\Services\GuildBonusService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
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

    public function index(Request $request): AnonymousResourceCollection
    {
        $rarities = collect(config('loot.items', []))
            ->pluck('rarity')
            ->unique()
            ->values()
            ->all();

        $data = $request->validate([
            'rarity' => ['sometimes', 'string', Rule::in($rarities)],
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

    public function stats(Request $request): UserLootStatResource
    {
        $stats = UserLootStat::query()
            ->where('user_id', $request->user()->id)
            ->firstOrNew(['user_id' => $request->user()->id]);

        return new UserLootStatResource($stats);
    }

    public function globalStats(): GlobalLootStatResource
    {
        $stats = DroppedItem::query()
            ->selectRaw(
                'COUNT(*) as total_drops, COALESCE(SUM(CASE WHEN rarity = ? THEN 1 ELSE 0 END), 0) as legendary_count',
                ['legendary']
            )
            ->first();

        return new GlobalLootStatResource($stats);
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

        $droppedItem = DB::transaction(function () use ($data, $item): DroppedItem {
            $droppedItem = DroppedItem::query()->create([
                'user_id' => $data['user_id'],
                'item_name' => $item['name'],
                'rarity' => $item['rarity'],
                'source' => 'admin_grant',
                'quantity' => 1,
            ]);

            event(new LootDropped($droppedItem));

            return $droppedItem;
        });

        return (new DroppedItemResource($droppedItem))
            ->response()
            ->setStatusCode(201);
    }
}
