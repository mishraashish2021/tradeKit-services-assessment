<?php

namespace App\Services;

use App\Enums\OrderSide;
use App\Enums\OrderStatus;
use App\Events\OrderStateChanged;
use App\Models\LedgerEntry;
use App\Models\Order;
use App\Models\Position;
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

            if ($order->side === OrderSide::BUY) {
                $reservation = LedgerEntry::query()
                    ->where('order_id', $order->id)
                    ->where('type', 'RESERVATION')
                    ->first();

                if (! $reservation) {
                    throw new RuntimeException('Order reservation ledger entry was not found.');
                }

                $alreadyReleased = LedgerEntry::query()
                    ->where('order_id', $order->id)
                    ->where('type', 'RELEASE')
                    ->exists();

                if (! $alreadyReleased) {
                    app(WalletLedgerService::class)->release(
                        $reservation->wallet_id,
                        (string) $reservation->reserved_amount,
                        $order
                    );
                }
            } else {
                $position = Position::query()
                    ->where('user_id', $order->user_id)
                    ->where('asset_id', $order->asset_id)
                    ->lockForUpdate()
                    ->firstOrFail();
                if (bccomp($position->reserved_quantity, $order->quantity, 8) < 0) {
                    throw new RuntimeException('Reserved asset quantity is insufficient to release failed order.');
                }
                $position->reserved_quantity = bcsub($position->reserved_quantity, $order->quantity, 8);
                $position->save();
            }

            $previousStatus = $order->status->value;
            $order->status = OrderStatus::FAILED;
            $order->failure_reason = substr(
                $reason ?? 'Order execution failed after all retry attempts.',
                0,
                1000
            );
            $order->save();
            event(new OrderStateChanged($order->id, $order->user_id, 'order.failed', ['status' => $previousStatus], ['status' => OrderStatus::FAILED->value, 'reason' => $order->failure_reason], request()->ip()));

            return $order;
        }, 3);
    }
}
