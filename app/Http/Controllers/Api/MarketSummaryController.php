<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Asset;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class MarketSummaryController extends Controller
{
    public function index(): JsonResponse
    {
        /** @var array<int, array{
         *     asset_id: int,
         *     symbol: string,
         *     name: string,
         *     latest_price: numeric-string|null
         * }> $summary
         */
        $summary = Cache::remember(
            'market:summary:v1',
            now()->addSeconds(10),
            /**
             * @return array<int, array{
             *     asset_id: int,
             *     symbol: string,
             *     name: string,
             *     latest_price: numeric-string|null
             * }>
             */
            function (): array {
                return Asset::query()
                    ->where('is_tradable', true)
                    ->select(['id', 'symbol', 'name'])
                    ->addSelect([
                        'latest_price' => DB::table('market_ticks')
                            ->select('price')
                            ->whereColumn('market_ticks.asset_id', 'assets.id')
                            ->orderByDesc('recorded_at')
                            ->orderByDesc('id')
                            ->limit(1),
                    ])
                    ->orderBy('symbol')
                    ->get()
                    ->map(
                        /**
                         * @return array{
                         *     asset_id: int,
                         *     symbol: string,
                         *     name: string,
                         *     latest_price: numeric-string|null
                         * }
                         */
                        fn (Asset $asset): array => [
                            'asset_id' => $asset->id,
                            'symbol' => $asset->symbol,
                            'name' => $asset->name,
                            'latest_price' => $asset->latest_price,
                        ]
                    )
                    ->all();
            }
        );

        return response()->json([
            'data' => $summary,
        ]);
    }
}
