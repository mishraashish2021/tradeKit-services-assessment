<?php

namespace App\Services;

use App\Models\Asset;
use App\Models\MarketTick;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class MarketTickService
{
    public function record(int $assetId, string $price): MarketTick
    {
        if (! preg_match('/^\d+(\.\d{1,8})?$/', $price)) {
            throw new InvalidArgumentException(
                'Price must be a non-negative decimal with up to 8 decimal places.'
            );
        }

        if (bccomp($price, '0', 8) <= 0) {
            throw new InvalidArgumentException(
                'Market price must be greater than zero.'
            );
        }

        $tick = DB::transaction(function () use ($assetId, $price) {
            $asset = Asset::query()
                ->whereKey($assetId)
                ->lockForUpdate()
                ->firstOrFail();

            if (! $asset->is_tradable) {
                throw new InvalidArgumentException(
                    'Cannot record a tick for a non-tradable asset.'
                );
            }

            return MarketTick::create([
                'asset_id' => $asset->id,
                'price' => $price,
                'recorded_at' => now(),
            ]);
        }, 3);

        Cache::put(
            "market:latest:{$assetId}",
            $tick->price,
            now()->addDay()
        );

        // Invalidate the cached summary so the next request sees the new tick.
        Cache::forget('market:summary:v1');

        return $tick;
    }
}
