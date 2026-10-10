<?php

namespace App\Jobs;

use App\Services\ExecuteBuyOrderService;
use App\Services\FailOrderService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class ExecuteOrderJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [1, 5, 15];

    public function __construct(
        public int $orderId
    ) {}

    public function handle(ExecuteBuyOrderService $service): void
    {
        $service->execute($this->orderId);
    }

    public function failed(?Throwable $exception): void
    {
        app(FailOrderService::class)->fail(
            $this->orderId,
            $exception?->getMessage() ?? 'Order execution failed permanently.'
        );
    }
}
