<?php

namespace App\Listeners;

use App\Events\OrderStateChanged;
use App\Services\MarketSummaryCacheInvalidator;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

class InvalidateMarketSummary implements ShouldQueue
{
    use InteractsWithQueue;

    public string $queue = 'default';

    public function handle(OrderStateChanged $event): void
    {
        if ($event->action === 'order.filled') {
            app(MarketSummaryCacheInvalidator::class)->invalidateAfterFill();
        }
    }
}
