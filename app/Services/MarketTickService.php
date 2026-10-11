<?php

namespace App\Services;

use App\Jobs\ExecuteOrderJob;
use App\Models\Asset;
use App\Models\MarketTick;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class MarketTickService
{
    public function record(int $assetId, string $price): MarketTick
    {
        $this->validatePrice($price);
        $asset = Asset::query()->findOrFail($assetId);
        if (! $asset->is_tradable) {
            throw new InvalidArgumentException('Cannot record a tick for a non-tradable asset.');
        }
        $tick = MarketTick::create(['asset_id' => $assetId, 'price' => $price, 'recorded_at' => now()]);
        Cache::put("market:latest:{$assetId}", $price, now()->addDay());
        Cache::put("market:latest_at:{$assetId}", now()->timestamp, now()->addDay());
        // Summary prices may be up to 60 seconds stale; avoid cache churn at feed rate.
        $this->dispatchEligibleOrders($assetId, $price);

        return $tick;
    }

    /** @param array<int, array{asset_id:int,price:string}> $ticks */
    public function recordBatch(array $ticks): void
    {
        if ($ticks === []) {
            return;
        }
        $now = now();
        $rows = [];
        $latest = [];
        foreach ($ticks as $tick) {
            $this->validatePrice($tick['price']);
            $latest[$tick['asset_id']] = $tick['price'];
            $rows[] = ['asset_id' => $tick['asset_id'], 'price' => $tick['price'], 'recorded_at' => $now];
        }
        $tradableIds = Asset::query()->whereIn('id', array_keys($latest))->where('is_tradable', true)->pluck('id')->map(fn ($id) => (int) $id)->all();
        if (count($tradableIds) !== count(array_unique(array_keys($latest)))) {
            throw new InvalidArgumentException('Ticks can only be recorded for tradable assets.');
        }
        DB::table('market_ticks')->insert($rows);
        foreach ($latest as $assetId => $price) {
            Cache::put("market:latest:{$assetId}", $price, now()->addDay());
            Cache::put("market:latest_at:{$assetId}", $now->timestamp, now()->addDay());
            $this->dispatchEligibleOrders((int) $assetId, $price);
        }
        // The summary is TTL-bounded; fills invalidate it immediately after commit.
    }

    private function dispatchEligibleOrders(int $assetId, string $price): void
    {
        $query = DB::table('orders')->where('asset_id', $assetId)->where('status', 'PENDING')
            ->where(function ($query) use ($price): void {
                $query->where('type', 'MARKET')
                    ->orWhere(function ($query) use ($price): void {
                        $query->where('type', 'LIMIT')->where(function ($query) use ($price): void {
                            $query->where(function ($query) use ($price): void {
                                $query->where('side', 'BUY')->where('limit_price', '>=', $price);
                            })
                                ->orWhere(function ($query) use ($price): void {
                                    $query->where('side', 'SELL')->where('limit_price', '<=', $price);
                                });
                        });
                    });
            });
        foreach ($query->select('id')->orderBy('id')->cursor() as $row) {
            $key = "order:execute-dispatch:{$row->id}";
            if (Cache::add($key, true, now()->addSeconds(2))) {
                ExecuteOrderJob::dispatch((int) $row->id);
            }
        }
    }

    private function validatePrice(string $price): void
    {
        if (! preg_match('/^\d+(\.\d{1,8})?$/', $price)) {
            throw new InvalidArgumentException('Price must be a positive decimal with up to 8 decimal places.');
        }
        if (bccomp($price, '0', 8) <= 0) {
            throw new InvalidArgumentException('Market price must be greater than zero.');
        }
    }
}
