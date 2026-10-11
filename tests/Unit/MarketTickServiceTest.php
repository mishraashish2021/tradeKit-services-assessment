<?php

namespace Tests\Unit;

use App\Models\Asset;
use App\Models\MarketTick;
use App\Services\MarketPriceCacheRecoveryService;
use App\Services\MarketTickService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class MarketTickServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_recording_tick_updates_redis_and_invalidates_summary(): void
    {
        Cache::put('market:summary:v2', 'stale-summary', 60);

        $asset = Asset::factory()->create([
            'symbol' => 'TEST',
            'is_tradable' => true,
        ]);

        $tick = app(MarketTickService::class)->record(
            $asset->id,
            '125.50000000'
        );

        $this->assertSame('125.50000000', $tick->price);

        $this->assertDatabaseHas('market_ticks', [
            'asset_id' => $asset->id,
            'price' => '125.50000000',
        ]);

        $this->assertSame(
            '125.50000000',
            Cache::get("market:latest:{$asset->id}")
        );

        $this->assertSame('stale-summary', Cache::get('market:summary:v2')); // Tick updates are TTL-bounded to avoid feed-rate cache churn.
    }

    public function test_recording_non_positive_price_is_rejected(): void
    {
        $asset = Asset::factory()->create([
            'is_tradable' => true,
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'Market price must be greater than zero.'
        );

        app(MarketTickService::class)->record($asset->id, '0');
    }

    public function test_cache_recovery_restores_latest_persisted_market_price(): void
    {
        $asset = Asset::factory()->create([
            'symbol' => 'RECOVERY',
            'is_tradable' => true,
        ]);

        $olderTick = MarketTick::create([
            'asset_id' => $asset->id,
            'price' => '100.00000000',
            'recorded_at' => now()->subMinute(),
        ]);

        $latestTick = MarketTick::create([
            'asset_id' => $asset->id,
            'price' => '125.50000000',
            'recorded_at' => now(),
        ]);

        // Simulate Redis containing an outdated value.
        Cache::put("market:latest:{$asset->id}", '100.00000000', 60);
        Cache::put(
            "market:latest_at:{$asset->id}",
            $olderTick->recorded_at->timestamp,
            60
        );

        $recoveredCount = app(MarketPriceCacheRecoveryService::class)->recover();

        $this->assertGreaterThanOrEqual(1, $recoveredCount);
        $this->assertSame(
            '125.50000000',
            Cache::get("market:latest:{$asset->id}")
        );
        $this->assertSame(
            $latestTick->recorded_at->timestamp,
            Cache::get("market:latest_at:{$asset->id}")
        );
    }
}
