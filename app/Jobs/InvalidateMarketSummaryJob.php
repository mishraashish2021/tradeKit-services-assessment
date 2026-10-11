<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

class InvalidateMarketSummaryJob implements ShouldQueue
{
    use Queueable;

    public function handle(): void
    {
        Cache::forget('market:summary:v2');
        Cache::forget('market:summary:invalidation-pending');
    }
}
