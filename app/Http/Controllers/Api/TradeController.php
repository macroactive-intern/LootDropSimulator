<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\AcceptTradeRequest;
use App\Http\Requests\CancelTradeRequest;
use App\Http\Requests\IndexTradeRequest;
use App\Http\Requests\ProposeTradeRequest;
use App\Http\Requests\RejectTradeRequest;
use App\Http\Resources\TradeResource;
use App\Models\Trade;
use App\Services\TradeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class TradeController extends Controller
{
    public function index(IndexTradeRequest $request): AnonymousResourceCollection
    {
        $trades = Trade::query()
            ->with($this->tradeRelations())
            ->where(function ($query) use ($request): void {
                $query->where('initiator_id', $request->user()->id)
                    ->orWhere('recipient_id', $request->user()->id);
            })
            ->when(
                $request->statusFilter(),
                fn ($query, string $status) => $query->where('status', $status)
            )
            ->latest()
            ->paginate(15);

        return TradeResource::collection($trades);
    }

    public function show(Request $request, Trade $trade): TradeResource
    {
        abort_unless($trade->involvesUser($request->user()), 403);

        return new TradeResource($trade->load($this->tradeRelations()));
    }

    public function store(ProposeTradeRequest $request, TradeService $tradeService): JsonResponse
    {
        $trade = $tradeService->propose($request->user(), $request->validated());

        return (new TradeResource($trade))
            ->response()
            ->setStatusCode(201);
    }

    public function accept(AcceptTradeRequest $request, Trade $trade, TradeService $tradeService): TradeResource
    {
        return new TradeResource($tradeService->accept($trade, $request->user()));
    }

    public function reject(RejectTradeRequest $request, Trade $trade, TradeService $tradeService): TradeResource
    {
        return new TradeResource($tradeService->reject($trade, $request->user()));
    }

    public function cancel(CancelTradeRequest $request, Trade $trade, TradeService $tradeService): TradeResource
    {
        return new TradeResource($tradeService->cancel($trade, $request->user()));
    }

    /**
     * @return array<int, string>
     */
    private function tradeRelations(): array
    {
        return [
            'tradeItems.inventoryItem.item',
            'escrowItems.inventoryItem.item',
        ];
    }
}
