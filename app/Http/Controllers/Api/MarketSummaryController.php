<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\MarketSummaryResource;
use App\Models\Asset;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class MarketSummaryController extends Controller
{
    public function index(): MarketSummaryResource
    {
        $cacheKey = 'market:summary:v2';
        $cached = Cache::get($cacheKey);
        if (is_array($cached)) {
            return new MarketSummaryResource($cached);
        }

        // The distributed lock prevents concurrent cache misses from all running the aggregates.
        $summary = Cache::lock('market:summary:build-lock', 15)->block(5, function () use ($cacheKey): array {
            $cached = Cache::get($cacheKey);
            if (is_array($cached)) {
                return $cached;
            }
            $cutoff = now()->subDay();
            $assets = Asset::query()->where('is_tradable', true)
                ->select(['id', 'symbol', 'name'])
                ->addSelect([
                    'latest_price' => DB::table('market_ticks')->select('price')->whereColumn('asset_id', 'assets.id')->orderByDesc('recorded_at')->orderByDesc('id')->limit(1),
                    'reference_price' => DB::table('market_ticks')->select('price')->whereColumn('asset_id', 'assets.id')->where('recorded_at', '<=', $cutoff)->orderByDesc('recorded_at')->orderByDesc('id')->limit(1),
                ])->orderBy('symbol')->get();

            $prices = $assets->map(function (Asset $asset): array {
                $latest = $asset->latest_price;
                $reference = $asset->reference_price;
                $change = null;
                if ($latest !== null && $reference !== null && bccomp((string) $reference, '0', 8) > 0) {
                    $change = bcmul(bcdiv(bcsub((string) $latest, (string) $reference, 8), (string) $reference, 12), '100', 4);
                }

                return [
                    'asset_id' => $asset->id,
                    'symbol' => $asset->symbol,
                    'name' => $asset->name,
                    'latest_price' => $latest,
                    'price_24h_ago' => $reference,
                    'change_24h_percent' => $change,
                ];
            })->all();

            $filledCount = DB::table('executions')->join('orders', 'orders.id', '=', 'executions.order_id')
                ->where('orders.status', 'FILLED')->where('executions.executed_at', '>=', $cutoff)->count('orders.id');

            $topAssets = DB::table('executions')->join('orders', 'orders.id', '=', 'executions.order_id')
                ->join('assets', 'assets.id', '=', 'orders.asset_id')
                ->where('orders.status', 'FILLED')
                ->where('executions.executed_at', '>=', $cutoff)
                ->select('assets.id as asset_id', 'assets.symbol', 'assets.name')
                ->selectRaw('SUM(executions.price * executions.quantity) AS notional_volume')
                ->groupBy('assets.id', 'assets.symbol', 'assets.name')
                ->orderByDesc('notional_volume')->limit(5)->get()
                ->map(fn ($row): array => [
                    'asset_id' => (int) $row->asset_id,
                    'symbol' => $row->symbol,
                    'name' => $row->name,
                    'notional_volume' => (string) $row->notional_volume,
                ])->all();

            $result = [
                'period' => ['from' => $cutoff->toISOString(), 'to' => now()->toISOString()],
                'filled_orders_24h' => $filledCount,
                'top_assets_by_notional_volume' => $topAssets,
                'assets' => $prices,
            ];
            Cache::put($cacheKey, $result, now()->addSeconds(60));

            return $result;
        });

        return new MarketSummaryResource($summary);
    }
}
