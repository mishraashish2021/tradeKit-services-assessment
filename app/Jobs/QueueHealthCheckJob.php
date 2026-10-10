<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

class QueueHealthCheckJob implements ShouldQueue
{
    use Queueable;

    public function handle(): void
    {
        Cache::put('queue_health_check', 'processed', 300);
    }
}
