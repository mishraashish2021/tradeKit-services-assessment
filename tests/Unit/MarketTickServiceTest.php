<?php

namespace Tests\Unit;

use App\Models\Asset;
use App\Services\MarketTickService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class MarketTickServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_recording_tick_updates_redis_and_invalidates_summary(): void
    {
        Cache::put('market:summary:v1', 'stale-summary', 60);

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

        $this->assertNull(Cache::get('market:summary:v1'));
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
}
