<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\InventoryItemResource;
use App\Models\InventoryItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class InventoryController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $inventoryItems = InventoryItem::query()
            ->with('item')
            ->where('user_id', $request->user()->id)
            ->latest()
            ->paginate(15);

        return InventoryItemResource::collection($inventoryItems);
    }
}
