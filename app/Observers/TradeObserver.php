<?php

namespace App\Observers;

use App\Jobs\ExpireTradeJob;
use App\Models\Trade;

class TradeObserver
{
    public function created(Trade $trade): void
    {
        if (! $trade->isPending()) {
            return;
        }

        ExpireTradeJob::dispatch($trade->id)->delay($trade->expires_at);
    }
}
