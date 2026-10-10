<?php

namespace App\Services;

use App\Enums\OrderSide;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Jobs\ExecuteOrderJob;
use App\Models\Asset;
use App\Models\Order;
use App\Models\Wallet;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class PlaceBuyOrderService
{
    /**
     * @param array{
     *     asset_id: int|string,
     *     side: string,
     *     type: string,
     *     quantity: numeric-string,
     *     limit_price: numeric-string|null,
     *     idempotency_key: string
     * } $data
     */
    public function place(int $userId, array $data): Order
    {
        if ($data['side'] !== OrderSide::BUY->value) {
            throw new RuntimeException(
                'This service only supports BUY orders.'
            );
        }

        $requestHash = hash('sha256', json_encode([
            'asset_id' => (int) $data['asset_id'],
            'side' => $data['side'],
            'type' => $data['type'],
            'quantity' => $data['quantity'],
            'limit_price' => $data['limit_price'] ?? null,
        ], JSON_THROW_ON_ERROR));

        // Fast path for retries. The unique DB constraint handles races.
        $existing = Order::query()
            ->where('user_id', $userId)
            ->where('idempotency_key', $data['idempotency_key'])
            ->first();

        if ($existing) {
            return $this->resolveRetry($existing, $requestHash);
        }

        try {
            $order = DB::transaction(function () use (
                $userId,
                $data,
                $requestHash
            ) {
                $asset = Asset::query()
                    ->whereKey($data['asset_id'])
                    ->lockForUpdate()
                    ->firstOrFail();

                if (! $asset->is_tradable) {
                    throw new RuntimeException('Asset is not tradable.');
                }

                if ($data['type'] === OrderType::MARKET->value) {
                    throw new RuntimeException(
                        'MARKET orders will be enabled after market-price reservation is implemented.'
                    );
                }

                $quantity = $data['quantity'];
                $limitPrice = $data['limit_price'];

                if (
                    bccomp(
                        $quantity,
                        '0',
                        (int) $asset->quantity_precision
                    ) <= 0
                ) {
                    throw new RuntimeException('Invalid order quantity.');
                }

                $amount = bcmul($quantity, $limitPrice, 16);
                $roundedAmount = bcadd($amount, '0', 8);

                // Round the reservation upward to 8 decimal places.
                $fraction = explode('.', $amount)[1] ?? '';

                if (
                    strlen($fraction) > 8
                    && trim(substr($fraction, 8), '0') !== ''
                ) {
                    $roundedAmount = bcadd(
                        $roundedAmount,
                        '0.00000001',
                        8
                    );
                }

                $wallet = Wallet::query()
                    ->where('user_id', $userId)
                    ->where('currency', 'INR')
                    ->lockForUpdate()
                    ->firstOrFail();

                $order = Order::create([
                    'uuid' => (string) Str::uuid(),
                    'user_id' => $userId,
                    'asset_id' => $asset->id,
                    'side' => OrderSide::BUY,
                    'type' => OrderType::LIMIT,
                    'quantity' => $quantity,
                    'limit_price' => $limitPrice,
                    'status' => OrderStatus::PENDING,
                    'idempotency_key' => $data['idempotency_key'],
                    'request_hash' => $requestHash,
                ]);

                app(WalletLedgerService::class)->reserve(
                    $wallet->id,
                    $roundedAmount,
                    $order
                );

                return $order;
            }, 3);

            // The transaction has committed before the job is dispatched.
            ExecuteOrderJob::dispatch($order->id);

            return $order;
        } catch (QueryException $exception) {
            // Another concurrent request may have inserted this key.
            $existing = Order::query()
                ->where('user_id', $userId)
                ->where('idempotency_key', $data['idempotency_key'])
                ->first();

            if ($existing) {
                return $this->resolveRetry($existing, $requestHash);
            }

            throw $exception;
        }

    }

    private function resolveRetry(
        Order $existing,
        string $requestHash
    ): Order {
        if (! hash_equals($existing->request_hash, $requestHash)) {
            throw new RuntimeException(
                'Idempotency key was already used with different order details.'
            );
        }

        return $existing;
    }
}
