<?php

namespace App\Services;

use App\Models\MarketTick;
use Illuminate\Support\Facades\Cache;

class MarketPriceCacheRecoveryService
{
    public function recover(): int
    {
        $ticks = MarketTick::query()
            ->whereHas('asset', fn ($query) => $query->where('is_tradable', true))
            ->whereNotExists(function ($query) {
                $query->selectRaw('1')
                    ->from('market_ticks as newer')
                    ->whereColumn('newer.asset_id', 'market_ticks.asset_id')
                    ->where(function ($query) {
                        $query->whereColumn(
                            'newer.recorded_at',
                            '>',
                            'market_ticks.recorded_at'
                        )->orWhere(function ($query) {
                            $query->whereColumn(
                                'newer.recorded_at',
                                '=',
                                'market_ticks.recorded_at'
                            )->whereColumn('newer.id', '>', 'market_ticks.id');
                        });
                    });
            })
            ->get();

        foreach ($ticks as $tick) {
            $timestamp = strtotime((string) $tick->recorded_at);

            if ($timestamp === false) {
                continue;
            }

            Cache::put(
                "market:latest:{$tick->asset_id}",
                (string) $tick->price,
                now()->addDay()
            );

            Cache::put(
                "market:latest_at:{$tick->asset_id}",
                $timestamp,
                now()->addDay()
            );
        }

        return $ticks->count();
    }
}
