<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Models\LedgerEntry;
use App\Models\Order;
use App\Models\Wallet;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class CancelBuyOrderService
{
    public function cancel(int $orderId, int $userId): Order
    {
        return DB::transaction(function () use ($orderId, $userId) {
            // Lock the order first. Execution must use the same lock.
            $order = Order::query()
                ->where('user_id', $userId)
                ->lockForUpdate()
                ->findOrFail($orderId);

            if ($order->status === OrderStatus::CANCELLED) {
                return $order;
            }

            if ($order->side->value !== 'BUY') {
                throw new RuntimeException(
                    'This service only supports BUY orders.'
                );
            }

            if ($order->status !== OrderStatus::PENDING) {
                throw new RuntimeException(
                    'Only pending orders can be cancelled.'
                );
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

            $amount = $reservation->reserved_amount;

            if (bccomp($amount, '0', 8) <= 0) {
                throw new RuntimeException(
                    'The order has no valid reserved amount.'
                );
            }

            $wallet = Wallet::query()
                ->where('user_id', $order->user_id)
                ->where('currency', 'INR')
                ->lockForUpdate()
                ->firstOrFail();

            // Avoid releasing the same reservation twice.
            $alreadyReleased = LedgerEntry::query()
                ->where('order_id', $order->id)
                ->where('type', 'RELEASE')
                ->exists();

            if ($alreadyReleased) {
                throw new RuntimeException(
                    'Funds for this order have already been released.'
                );
            }

            app(WalletLedgerService::class)->release(
                $wallet->id,
                $amount,
                $order
            );

            $order->status = OrderStatus::CANCELLED;
            $order->save();

            return $order;
        }, 3);
    }

    // public function destroy(
    //     int $order,
    //     CancelBuyOrderService $service
    // ): JsonResponse {
    //     try {
    //         $cancelledOrder = $service->cancel(
    //             $order,
    //             (int) auth()->id()
    //         );

    //         return response()->json([
    //             'message' => 'Order cancelled successfully.',
    //             'data' => $cancelledOrder->fresh(),
    //         ]);
    //     } catch (RuntimeException $exception) {
    //         return response()->json([
    //             'message' => $exception->getMessage(),
    //         ], 422);
    //     }
    // }
}
