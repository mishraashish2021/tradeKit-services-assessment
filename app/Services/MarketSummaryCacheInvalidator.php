<?php

namespace App\Services;

use App\Jobs\InvalidateMarketSummaryJob;
use Illuminate\Support\Facades\Cache;

class MarketSummaryCacheInvalidator
{
    public function invalidateAfterFill(): void
    {
        // Coalesce high-frequency fills into one invalidation per two-second window.
        if (Cache::add('market:summary:invalidation-pending', true, now()->addSeconds(2))) {
            InvalidateMarketSummaryJob::dispatch()->delay(now()->addSeconds(2));
        }
    }
}
