<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\Execution;
use App\Models\MarketTick;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MarketSummaryTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_view_market_summary(): void
    {
        $this->getJson('/api/v1/market/summary')->assertUnauthorized();
    }

    public function test_market_summary_returns_latest_price_and_only_tradable_assets(): void
    {
        Cache::forget('market:summary:v2');
        Sanctum::actingAs(User::factory()->create());

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
            ->assertJsonCount(1, 'data.assets')
            ->assertJsonPath('data.assets.0.symbol', 'TEST')
            ->assertJsonPath('data.assets.0.latest_price', '125.50000000')
            ->assertJsonPath('data.filled_orders_24h', 0)
            ->assertJsonPath('data.top_assets_by_notional_volume', []);
    }

    public function test_market_summary_calculates_filled_orders_volume_and_24_hour_change(): void
    {
        Cache::forget('market:summary:v2');
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        $asset = Asset::factory()->create(['symbol' => 'METRIC', 'name' => 'Metric Asset', 'is_tradable' => true]);
        MarketTick::create(['asset_id' => $asset->id, 'price' => '100.00000000', 'recorded_at' => now()->subDay()->subMinute()]);
        MarketTick::create(['asset_id' => $asset->id, 'price' => '125.00000000', 'recorded_at' => now()]);
        $order = Order::create([
            'uuid' => (string) str()->uuid(), 'user_id' => $user->id, 'asset_id' => $asset->id,
            'side' => 'BUY', 'type' => 'LIMIT', 'quantity' => '2.00000000',
            'limit_price' => '130.00000000', 'status' => 'FILLED',
            'idempotency_key' => 'summary-filled-001', 'request_hash' => hash('sha256', 'summary-filled-001'),
        ]);
        Execution::create(['order_id' => $order->id, 'price' => '125.00000000', 'quantity' => '2.00000000', 'fee' => '0.00000000', 'executed_at' => now()]);

        $response = $this->getJson('/api/v1/market/summary')->assertOk();
        $response->assertJsonPath('data.filled_orders_24h', 1)
            ->assertJsonPath('data.assets.0.change_24h_percent', '25.0000')
            ->assertJsonPath('data.top_assets_by_notional_volume.0.symbol', 'METRIC');
        $this->assertSame(0, bccomp('250.0000000000000000', $response->json('data.top_assets_by_notional_volume.0.notional_volume'), 8));
    }
}
