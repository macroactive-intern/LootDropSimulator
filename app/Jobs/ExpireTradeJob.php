<?php

namespace App\Jobs;

use App\Models\Trade;
use App\Services\TradeService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ExpireTradeJob implements ShouldQueue
{
    use Queueable;

    public int $tradeId;

    public function __construct(Trade|int $trade)
    {
        $this->tradeId = $trade instanceof Trade ? $trade->id : $trade;
    }

    public function handle(TradeService $tradeService): void
    {
        $trade = Trade::query()->find($this->tradeId);

        if ($trade === null || ! $trade->isPending()) {
            return;
        }

        $tradeService->expireIfPending($trade);
    }
}
