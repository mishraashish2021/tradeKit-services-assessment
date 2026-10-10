<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Models\LedgerEntry;
use App\Models\Order;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class FailOrderService
{
    public function fail(int $orderId, ?string $reason = null): Order
    {
        return DB::transaction(function () use ($orderId, $reason) {
            $order = Order::query()
                ->lockForUpdate()
                ->findOrFail($orderId);

            // Never change a completed or cancelled order.
            if (in_array($order->status, [
                OrderStatus::FILLED,
                OrderStatus::CANCELLED,
            ], true)) {
                return $order;
            }

            // Repeated failure handling must not release funds again.
            if ($order->status === OrderStatus::FAILED) {
                return $order;
            }

            // Only orders that may still hold a reservation are handled.
            if (! in_array($order->status, [
                OrderStatus::PENDING,
                OrderStatus::PROCESSING,
            ], true)) {
                return $order;
            }

            $reservation = LedgerEntry::query()
                ->where('order_id', $order->id)
                ->where('type', 'RESERVATION')
                ->first();

            if (! $reservation) {
                throw new RuntimeException(
                    'Order reservation ledger entry was not found.'
                );
            }

            $alreadyReleased = LedgerEntry::query()
                ->where('order_id', $order->id)
                ->where('type', 'RELEASE')
                ->exists();

            if (! $alreadyReleased) {
                app(WalletLedgerService::class)->release(
                    $reservation->wallet_id,
                    $reservation->reserved_amount,
                    $order
                );
            }

            $order->status = OrderStatus::FAILED;
            $order->failure_reason = substr(
                $reason ?? 'Order execution failed after all retry attempts.',
                0,
                1000
            );
            $order->save();

            return $order;
        }, 3);
    }
}
