<?php

namespace App\Services;

use App\Enums\OrderSide;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Events\OrderStateChanged;
use App\Jobs\ExecuteOrderJob;
use App\Models\Asset;
use App\Models\Order;
use App\Models\Position;
use App\Models\Wallet;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class PlaceBuyOrderService
{
    /** @param array{asset_id:int|string,side:string,type:string,quantity:numeric-string,limit_price:numeric-string|null,idempotency_key:string} $data */
    public function place(int $userId, array $data): Order
    {
        $requestHash = hash('sha256', json_encode([
            'asset_id' => (int) $data['asset_id'],
            'side' => $data['side'],
            'type' => $data['type'],
            'quantity' => $data['quantity'],
            'limit_price' => $data['limit_price'] ?? null,
        ], JSON_THROW_ON_ERROR));

        $existing = Order::query()->where('user_id', $userId)
            ->where('idempotency_key', $data['idempotency_key'])->first();
        if ($existing) {
            return $this->resolveRetry($existing, $requestHash);
        }

        try {
            $order = DB::transaction(function () use ($userId, $data, $requestHash): Order {
                $asset = Asset::query()->whereKey($data['asset_id'])->lockForUpdate()->firstOrFail();
                if (! $asset->is_tradable) {
                    throw new RuntimeException('Asset is not tradable.');
                }

                $side = OrderSide::from($data['side']);
                $type = OrderType::from($data['type']);
                $quantity = (string) $data['quantity'];
                $limitPrice = $data['limit_price'] ?? null;
                $marketPrice = null;
                if ($type === OrderType::MARKET) {
                    $cachedPrice = Cache::get("market:latest:{$asset->id}");
                    if (! is_string($cachedPrice) || bccomp($cachedPrice, '0', 8) <= 0) {
                        throw new RuntimeException('No current market price is available for this asset.');
                    }
                    $marketPrice = $cachedPrice;
                } elseif (! is_string($limitPrice)) {
                    throw new RuntimeException('A limit price is required for LIMIT orders.');
                }

                $order = Order::create([
                    'uuid' => (string) Str::uuid(),
                    'user_id' => $userId,
                    'asset_id' => $asset->id,
                    'side' => $side,
                    'type' => $type,
                    'quantity' => $quantity,
                    'limit_price' => $limitPrice,
                    'status' => OrderStatus::PENDING,
                    'idempotency_key' => $data['idempotency_key'],
                    'request_hash' => $requestHash,
                    'expires_at' => $type === OrderType::MARKET ? now()->addSeconds(30) : now()->addDay(),
                ]);

                if ($side === OrderSide::BUY) {
                    $reservationPrice = $type === OrderType::MARKET ? $marketPrice : (string) $limitPrice;
                    $amount = $this->multiplyCeiling8($quantity, $reservationPrice);
                    // Reserve a 5% market-movement buffer for BUY MARKET orders.
                    if ($type === OrderType::MARKET) {
                        $amount = $this->multiplyCeiling8($amount, '1.05');
                    }
                    if (bccomp($amount, '0', 8) <= 0) {
                        throw new RuntimeException('Order reservation amount must be positive.');
                    }
                    $wallet = Wallet::query()->where('user_id', $userId)->where('currency', 'INR')->lockForUpdate()->firstOrFail();
                    app(WalletLedgerService::class)->reserve($wallet->id, $amount, $order);
                } else {
                    $position = Position::query()->where('user_id', $userId)->where('asset_id', $asset->id)->lockForUpdate()->first();
                    if (! $position || bccomp(bcsub($position->quantity, $position->reserved_quantity, 8), $quantity, 8) < 0) {
                        throw new RuntimeException('Insufficient available asset quantity.');
                    }
                    $position->reserved_quantity = bcadd($position->reserved_quantity, $quantity, 8);
                    $position->save();
                }

                event(new OrderStateChanged($order->id, $userId, 'order.placed', [], ['status' => OrderStatus::PENDING->value, 'side' => $side->value, 'type' => $type->value], request()->ip()));

                return $order;
            }, 3);
        } catch (QueryException $exception) {
            $existing = Order::query()->where('user_id', $userId)->where('idempotency_key', $data['idempotency_key'])->first();
            if ($existing) {
                return $this->resolveRetry($existing, $requestHash);
            }
            throw $exception;
        }

        ExecuteOrderJob::dispatch($order->id);

        return $order;
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

    private function resolveRetry(Order $existing, string $requestHash): Order
    {
        if (! hash_equals($existing->request_hash, $requestHash)) {
            throw new RuntimeException('Idempotency key was already used with different order details.');
        }

        return $existing;
    }
}
