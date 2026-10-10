<?php

namespace App\Services;

use App\Enums\OrderSide;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Models\Execution;
use App\Models\LedgerEntry;
use App\Models\MarketTick;
use App\Models\Order;
use App\Models\Position;
use App\Models\Wallet;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** @property numeric-string $price */
class ExecuteBuyOrderService
{
    public function execute(int $orderId): Order
    {
        return DB::transaction(function () use ($orderId) {
            // Always lock the order first to coordinate with cancellation.
            $order = Order::query()
                ->lockForUpdate()
                ->findOrFail($orderId);

            // A repeated job must not execute a filled order again.
            if ($order->status === OrderStatus::FILLED) {
                return $order;
            }

            if ($order->status === OrderStatus::CANCELLED) {
                return $order;
            }

            if (
                $order->side !== OrderSide::BUY ||
                $order->type !== OrderType::LIMIT
            ) {
                throw new RuntimeException(
                    'Only BUY LIMIT orders are supported by this execution service.'
                );
            }

            if (! in_array(
                $order->status,
                [OrderStatus::PENDING, OrderStatus::PROCESSING],
                true
            )) {
                return $order;
            }

            // Only execute when the latest market price meets the limit.
            $tick = MarketTick::query()
                ->where('asset_id', $order->asset_id)
                ->orderByDesc('recorded_at')
                ->orderByDesc('id')
                ->first();

            if (! $tick || bccomp(
                $tick->price,
                $order->limit_price,
                8
            ) > 0) {
                return $order;
            }

            // Protect against duplicate execution records.
            if ($order->execution()->exists()) {
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

            $reservedAmount = $reservation->reserved_amount;
            $executionPrice = $tick->price;
            $quantity = $order->quantity;

            $executionCost = bcmul(
                $executionPrice,
                $quantity,
                8
            );

            if (bccomp($executionCost, $reservedAmount, 8) > 0) {
                throw new RuntimeException(
                    'Execution cost exceeds reserved funds.'
                );
            }

            $refund = bcsub($reservedAmount, $executionCost, 8);

            $wallet = Wallet::query()
                ->where('user_id', $order->user_id)
                ->where('currency', 'INR')
                ->lockForUpdate()
                ->firstOrFail();

            if (bccomp(
                $wallet->reserved_balance,
                $reservedAmount,
                8
            ) < 0) {
                throw new RuntimeException(
                    'Insufficient reserved balance for execution.'
                );
            }

            $wallet->reserved_balance = bcsub(
                $wallet->reserved_balance,
                $reservedAmount,
                8
            );

            $wallet->available_balance = bcadd(
                $wallet->available_balance,
                $refund,
                8
            );

            $wallet->save();

            // Update or create the user's position.
            $position = Position::query()
                ->where('user_id', $order->user_id)
                ->where('asset_id', $order->asset_id)
                ->lockForUpdate()
                ->first();

            if (! $position) {
                $position = new Position;
                $position->user_id = $order->user_id;
                $position->asset_id = $order->asset_id;
                $position->quantity = '0.00000000';
                $position->reserved_quantity = '0.00000000';
                $position->average_cost = '0.00000000';
            }

            $oldQuantity = $position->quantity;
            $oldCost = bcmul(
                $oldQuantity,
                $position->average_cost,
                8
            );

            $newQuantity = bcadd($oldQuantity, $quantity, 8);
            $newTotalCost = bcadd($oldCost, $executionCost, 8);

            $position->quantity = $newQuantity;
            $position->average_cost = bcdiv(
                $newTotalCost,
                $newQuantity,
                8
            );
            $position->save();

            // Persist the execution.
            $execution = new Execution;
            $execution->order_id = $order->id;
            $execution->price = $executionPrice;
            $execution->quantity = $quantity;
            $execution->fee = '0.00000000';
            $execution->executed_at = now();
            $execution->save();

            // Record the wallet changes for reconciliation.
            LedgerEntry::create([
                'wallet_id' => $wallet->id,
                'order_id' => $order->id,
                'type' => 'EXECUTION',
                'amount' => $refund,
                'reserved_amount' => '-'.$reservedAmount,
                'balance_after' => $wallet->available_balance,
                'reserved_balance_after' => $wallet->reserved_balance,
            ]);

            $order->status = OrderStatus::FILLED;
            $order->save();

            return $order;
        }, 3);
    }
}
