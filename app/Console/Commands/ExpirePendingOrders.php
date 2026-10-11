<?php

namespace App\Console\Commands;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Services\CancelBuyOrderService;
use Illuminate\Console\Command;
use Throwable;

class ExpirePendingOrders extends Command
{
    protected $signature = 'orders:expire';

    protected $description = 'Cancel expired pending orders and release their reservations';

    public function handle(CancelBuyOrderService $service): int
    {
        $expired = Order::query()->where('status', OrderStatus::PENDING->value)->where('type', 'LIMIT')
            ->whereNotNull('expires_at')->where('expires_at', '<=', now())
            ->select(['id', 'user_id'])->orderBy('id')->get();
        $failed = 0;
        foreach ($expired as $order) {
            try {
                $service->cancel((int) $order->id, (int) $order->user_id);
            } catch (Throwable $exception) {
                $failed++;
                report($exception);
            }
        }
        $this->info('Expired orders cancelled: '.($expired->count() - $failed).'; failures: '.$failed.'.');

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
