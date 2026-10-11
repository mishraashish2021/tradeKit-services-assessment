<?php

namespace App\Services;

use App\Enums\OrderSide;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Events\OrderFilled;
use App\Events\OrderRejected;
use App\Models\Execution;
use App\Models\LedgerEntry;
use App\Models\Order;
use App\Models\Position;
use App\Models\Wallet;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ExecuteBuyOrderService
{
    public function execute(int $orderId): Order
    {
        return DB::transaction(function () use ($orderId): Order {
            $order = Order::query()->lockForUpdate()->findOrFail($orderId);
            if (in_array($order->status, [OrderStatus::FILLED, OrderStatus::CANCELLED, OrderStatus::FAILED, OrderStatus::REJECTED], true)) {
                return $order;
            }
            if (! in_array($order->status, [OrderStatus::PENDING, OrderStatus::PROCESSING], true)) {
                return $order;
            }
            if ($order->execution()->exists()) {
                return $order;
            }

            $quantity = (string) $order->quantity;
            $price = Cache::get("market:latest:{$order->asset_id}");
            $latestAt = Cache::get("market:latest_at:{$order->asset_id}");
            if ($order->type === OrderType::MARKET && (! is_numeric($latestAt) || (int) $latestAt <= now()->subSeconds(30)->timestamp)) {
                if ($order->side === OrderSide::BUY) {
                    $reservation = LedgerEntry::query()->where('order_id', $order->id)->where('type', 'RESERVATION')->firstOrFail();
                    app(WalletLedgerService::class)->release($reservation->wallet_id, (string) $reservation->reserved_amount, $order);
                } else {
                    $position = Position::query()->where('user_id', $order->user_id)->where('asset_id', $order->asset_id)->lockForUpdate()->firstOrFail();
                    if (bccomp($position->reserved_quantity, $quantity, 8) < 0) {
                        throw new RuntimeException('Reserved asset quantity is insufficient for market rejection.');
                    }
                    $position->reserved_quantity = bcsub($position->reserved_quantity, $quantity, 8);
                    $position->save();
                }
                $previousStatus = $order->status->value;
                $order->status = OrderStatus::REJECTED;
                $order->failure_reason = 'Market price is missing or older than 30 seconds.';
                $order->save();
                event(new OrderRejected($order->id, $order->user_id, 'order.rejected', ['status' => $previousStatus], ['status' => OrderStatus::REJECTED->value, 'reason' => $order->failure_reason], request()->ip()));

                return $order;
            }
            if (! is_string($price) || bccomp($price, '0', 8) <= 0) {
                return $order;
            }

            if ($order->type === OrderType::LIMIT) {
                $comparison = bccomp($price, (string) $order->limit_price, 8);
                $canFill = $order->side === OrderSide::BUY ? $comparison <= 0 : $comparison >= 0;
                if (! $canFill) {
                    return $order;
                }
            }

            // Round BUY costs up to avoid under-reserving; round SELL proceeds down.
            $value = $order->side === OrderSide::BUY
                ? $this->multiplyCeiling8($price, $quantity)
                : bcmul($price, $quantity, 8);

            if ($order->side === OrderSide::BUY) {
                $reservation = LedgerEntry::query()->where('order_id', $order->id)->where('type', 'RESERVATION')->firstOrFail();
                $reservedAmount = (string) $reservation->reserved_amount;
                if (bccomp($value, $reservedAmount, 8) > 0) {
                    if ($order->type === OrderType::MARKET) {
                        app(WalletLedgerService::class)->release($reservation->wallet_id, $reservedAmount, $order);
                        $previousStatus = $order->status->value;
                        $order->status = OrderStatus::REJECTED;
                        $order->failure_reason = 'Market price exceeded the reserved 5% slippage buffer.';
                        $order->save();
                        event(new OrderRejected($order->id, $order->user_id, 'order.rejected', ['status' => $previousStatus], ['status' => OrderStatus::REJECTED->value, 'reason' => $order->failure_reason], request()->ip()));
                    }

                    // LIMIT orders remain pending if a price change makes settlement unsafe.
                    return $order;
                }
                $wallet = Wallet::query()->where('user_id', $order->user_id)->where('currency', 'INR')->lockForUpdate()->firstOrFail();
                if (bccomp($wallet->reserved_balance, $reservedAmount, 8) < 0) {
                    throw new RuntimeException('Insufficient reserved balance for execution.');
                }
                $refund = bcsub($reservedAmount, $value, 8);
                $wallet->reserved_balance = bcsub($wallet->reserved_balance, $reservedAmount, 8);
                $wallet->available_balance = bcadd($wallet->available_balance, $refund, 8);
                $wallet->save();
                LedgerEntry::create([
                    'wallet_id' => $wallet->id, 'order_id' => $order->id, 'type' => 'EXECUTION',
                    'amount' => $refund, 'reserved_amount' => '-'.$reservedAmount,
                    'balance_after' => $wallet->available_balance, 'reserved_balance_after' => $wallet->reserved_balance,
                ]);

                $position = Position::query()->where('user_id', $order->user_id)->where('asset_id', $order->asset_id)->lockForUpdate()->first();
                if (! $position) {
                    $position = new Position;
                    $position->user_id = $order->user_id;
                    $position->asset_id = $order->asset_id;
                    $position->quantity = '0.00000000';
                    $position->reserved_quantity = '0.00000000';
                    $position->average_cost = '0.00000000';
                }
                $oldQuantity = (string) $position->quantity;
                $oldCost = bcmul($oldQuantity, (string) $position->average_cost, 8);
                $newQuantity = bcadd($oldQuantity, $quantity, 8);
                $position->quantity = $newQuantity;
                $position->average_cost = bccomp($newQuantity, '0', 8) > 0 ? bcdiv(bcadd($oldCost, $value, 8), $newQuantity, 8) : '0.00000000';
                $position->save();
            } else {
                // Lock wallet before position in every settlement path to minimize deadlocks.
                $wallet = Wallet::query()->where('user_id', $order->user_id)->where('currency', 'INR')->lockForUpdate()->firstOrFail();
                $position = Position::query()->where('user_id', $order->user_id)->where('asset_id', $order->asset_id)->lockForUpdate()->firstOrFail();
                if (bccomp($position->reserved_quantity, $quantity, 8) < 0 || bccomp($position->quantity, $quantity, 8) < 0) {
                    throw new RuntimeException('Reserved asset quantity is insufficient for execution.');
                }
                $position->quantity = bcsub($position->quantity, $quantity, 8);
                $position->reserved_quantity = bcsub($position->reserved_quantity, $quantity, 8);
                $position->save();

                $wallet->available_balance = bcadd($wallet->available_balance, $value, 8);
                $wallet->save();
                LedgerEntry::create([
                    'wallet_id' => $wallet->id, 'order_id' => $order->id, 'type' => 'SELL_PROCEEDS',
                    'amount' => $value, 'reserved_amount' => '0.00000000',
                    'balance_after' => $wallet->available_balance, 'reserved_balance_after' => $wallet->reserved_balance,
                ]);
            }

            Execution::create([
                'order_id' => $order->id, 'price' => $price, 'quantity' => $quantity,
                'fee' => '0.00000000', 'executed_at' => now(),
            ]);
            $previousStatus = $order->status->value;
            $order->status = OrderStatus::FILLED;
            $order->save();
            event(new OrderFilled($order->id, $order->user_id, 'order.filled', ['status' => $previousStatus], ['status' => OrderStatus::FILLED->value, 'execution_price' => $price, 'quantity' => $quantity], request()->ip()));

            return $order;
        }, 3);
    }

    private function multiplyCeiling8(string $left, string $right): string
    {
        $precise = bcmul($left, $right, 16);
        $rounded = bcadd($precise, '0', 8);
        $fraction = explode('.', $precise)[1] ?? '';
        if (strlen($fraction) > 8 && trim(substr($fraction, 8), '0') !== '') {
            $rounded = bcadd($rounded, '0.00000001', 8);
        }

        return $rounded;
    }
}
