<?php

namespace App\Services;

use App\Enums\OrderSide;
use App\Enums\OrderStatus;
use App\Events\OrderCancelled;
use App\Models\LedgerEntry;
use App\Models\Order;
use App\Models\Position;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class CancelBuyOrderService
{
    public function cancel(int $orderId, int $userId): Order
    {
        return DB::transaction(function () use ($orderId, $userId): Order {
            $order = Order::query()->where('user_id', $userId)->lockForUpdate()->findOrFail($orderId);
            if ($order->status === OrderStatus::CANCELLED) {
                return $order;
            }
            if ($order->status !== OrderStatus::PENDING) {
                throw new RuntimeException('Only pending orders can be cancelled.');
            }

            if ($order->side === OrderSide::BUY) {
                $reservation = LedgerEntry::query()->where('order_id', $order->id)->where('type', 'RESERVATION')->firstOrFail();
                if (LedgerEntry::query()->where('order_id', $order->id)->where('type', 'RELEASE')->exists()) {
                    throw new RuntimeException('Funds for this order have already been released.');
                }
                app(WalletLedgerService::class)->release($reservation->wallet_id, (string) $reservation->reserved_amount, $order);
            } else {
                $position = Position::query()->where('user_id', $order->user_id)->where('asset_id', $order->asset_id)->lockForUpdate()->firstOrFail();
                if (bccomp($position->reserved_quantity, $order->quantity, 8) < 0) {
                    throw new RuntimeException('Reserved asset quantity is insufficient.');
                }
                $position->reserved_quantity = bcsub($position->reserved_quantity, $order->quantity, 8);
                $position->save();
            }
            $order->status = OrderStatus::CANCELLED;
            $order->save();
            event(new OrderCancelled($order->id, $userId, 'order.cancelled', ['status' => OrderStatus::PENDING->value], ['status' => OrderStatus::CANCELLED->value], request()->ip()));

            return $order;
        }, 3);
    }
}
