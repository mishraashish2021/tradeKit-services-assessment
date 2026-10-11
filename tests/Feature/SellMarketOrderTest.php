<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\MarketTick;
use App\Models\Position;
use App\Models\User;
use App\Models\Wallet;
use App\Services\CancelBuyOrderService;
use App\Services\PlaceBuyOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class SellMarketOrderTest extends TestCase
{
    use RefreshDatabase;

    public function test_sell_limit_reserves_position_quantity_and_cancellation_releases_it(): void
    {
        $user = User::factory()->create();
        $asset = Asset::factory()->create(['symbol' => 'SELLT', 'is_tradable' => true]);
        Wallet::create(['user_id' => $user->id, 'currency' => 'INR', 'available_balance' => '100.00000000', 'reserved_balance' => '0.00000000']);
        Position::create(['user_id' => $user->id, 'asset_id' => $asset->id, 'quantity' => '5.00000000', 'reserved_quantity' => '0.00000000', 'average_cost' => '10.00000000']);
        MarketTick::create(['asset_id' => $asset->id, 'price' => '10.00000000', 'recorded_at' => now()]);
        Cache::put("market:latest:{$asset->id}", '10.00000000', 60);

        $order = app(PlaceBuyOrderService::class)->place($user->id, [
            'asset_id' => $asset->id, 'side' => 'SELL', 'type' => 'LIMIT',
            'quantity' => '2.00000000', 'limit_price' => '12.00000000', 'idempotency_key' => 'sell-limit-reserve-1',
        ]);
        $this->assertSame('PENDING', $order->fresh()->status->value);
        $this->assertSame('2.00000000', Position::query()->where('user_id', $user->id)->where('asset_id', $asset->id)->value('reserved_quantity'));

        app(CancelBuyOrderService::class)->cancel($order->id, $user->id);
        $this->assertSame('0.00000000', Position::query()->where('user_id', $user->id)->where('asset_id', $asset->id)->value('reserved_quantity'));
    }

    public function test_buy_market_order_uses_redis_latest_price_and_executes(): void
    {
        $user = User::factory()->create();
        $asset = Asset::factory()->create(['symbol' => 'MARKETT', 'is_tradable' => true]);
        $wallet = Wallet::create(['user_id' => $user->id, 'currency' => 'INR', 'available_balance' => '1000.00000000', 'reserved_balance' => '0.00000000']);
        MarketTick::create(['asset_id' => $asset->id, 'price' => '100.00000000', 'recorded_at' => now()]);
        Cache::put("market:latest:{$asset->id}", '100.00000000', 60);
        Cache::put("market:latest_at:{$asset->id}", now()->timestamp, 60);

        $order = app(PlaceBuyOrderService::class)->place($user->id, [
            'asset_id' => $asset->id, 'side' => 'BUY', 'type' => 'MARKET',
            'quantity' => '2.00000000', 'limit_price' => null, 'idempotency_key' => 'market-buy-1',
        ]);

        $this->assertSame('FILLED', $order->fresh()->status->value);
        $this->assertSame('800.00000000', $wallet->fresh()->available_balance);
        $this->assertSame('0.00000000', $wallet->fresh()->reserved_balance);
        $this->assertDatabaseHas('executions', ['order_id' => $order->id, 'price' => '100.00000000', 'quantity' => '2.00000000']);
    }

    public function test_stale_market_price_rejects_market_order_and_releases_reservation(): void
    {
        $user = User::factory()->create();
        $asset = Asset::factory()->create(['symbol' => 'STALE', 'is_tradable' => true]);
        $wallet = Wallet::create(['user_id' => $user->id, 'currency' => 'INR', 'available_balance' => '1000.00000000', 'reserved_balance' => '0.00000000']);
        Cache::put("market:latest:{$asset->id}", '100.00000000', 60);
        Cache::put("market:latest_at:{$asset->id}", now()->subSeconds(31)->timestamp, 60);

        $order = app(PlaceBuyOrderService::class)->place($user->id, [
            'asset_id' => $asset->id, 'side' => 'BUY', 'type' => 'MARKET',
            'quantity' => '2.00000000', 'limit_price' => null, 'idempotency_key' => 'market-buy-stale-1',
        ]);

        $this->assertSame('REJECTED', $order->fresh()->status->value);
        $this->assertSame('1000.00000000', $wallet->fresh()->available_balance);
        $this->assertSame('0.00000000', $wallet->fresh()->reserved_balance);
    }
}
