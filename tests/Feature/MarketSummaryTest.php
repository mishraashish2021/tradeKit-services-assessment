<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\MarketTick;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class MarketSummaryTest extends TestCase
{
    use RefreshDatabase;

    public function test_market_summary_returns_latest_price_and_only_tradable_assets(): void
    {
        Cache::forget('market:summary:v1');

        $asset = Asset::factory()->create([
            'symbol' => 'TEST',
            'name' => 'Test Asset',
            'is_tradable' => true,
        ]);

        MarketTick::create([
            'asset_id' => $asset->id,
            'price' => '100.00000000',
            'recorded_at' => '2026-10-09 10:00:00',
        ]);

        MarketTick::create([
            'asset_id' => $asset->id,
            'price' => '125.50000000',
            'recorded_at' => '2026-10-09 10:01:00',
        ]);

        Asset::factory()->create([
            'symbol' => 'HIDDEN',
            'name' => 'Untradable Asset',
            'is_tradable' => false,
        ]);

        $response = $this->getJson('/api/v1/market/summary');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.symbol', 'TEST')
            ->assertJsonPath('data.0.latest_price', '125.50000000');
    }
}
