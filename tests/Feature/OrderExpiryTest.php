<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\Order;
use App\Models\User;
use App\Models\Wallet;
use App\Services\PlaceBuyOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderExpiryTest extends TestCase
{
    use RefreshDatabase;

    public function test_expiry_command_cancels_expired_limit_order_and_releases_funds(): void
    {
        $user = User::factory()->create();
        $asset = Asset::factory()->create(['symbol' => 'EXPIRY', 'is_tradable' => true]);
        $wallet = Wallet::create(['user_id' => $user->id, 'currency' => 'INR', 'available_balance' => '1000.00000000', 'reserved_balance' => '0.00000000']);
        $order = app(PlaceBuyOrderService::class)->place($user->id, [
            'asset_id' => $asset->id,
            'side' => 'BUY',
            'type' => 'LIMIT',
            'quantity' => '2.00000000',
            'limit_price' => '100.00000000',
            'idempotency_key' => 'expiry-order-001',
        ]);
        Order::query()->whereKey($order->id)->update(['expires_at' => now()->subMinute()]);

        $this->artisan('orders:expire')->assertExitCode(0);

        $this->assertSame('CANCELLED', $order->fresh()->status->value);
        $this->assertSame('1000.00000000', $wallet->fresh()->available_balance);
        $this->assertSame('0.00000000', $wallet->fresh()->reserved_balance);
    }
}
