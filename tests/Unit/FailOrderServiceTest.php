<?php

namespace Tests\Unit;

use App\Enums\OrderStatus;
use App\Models\Asset;
use App\Models\LedgerEntry;
use App\Models\Order;
use App\Models\User;
use App\Models\Wallet;
use App\Services\FailOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FailOrderServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_failed_order_releases_reserved_funds_only_once(): void
    {
        $user = User::factory()->create();

        $asset = Asset::factory()->create([
            'is_tradable' => true,
        ]);

        $wallet = Wallet::create([
            'user_id' => $user->id,
            'currency' => 'INR',
            'available_balance' => '9800.00000000',
            'reserved_balance' => '200.00000000',
        ]);

        $order = Order::create([
            'uuid' => (string) str()->uuid(),
            'user_id' => $user->id,
            'asset_id' => $asset->id,
            'side' => 'BUY',
            'type' => 'LIMIT',
            'quantity' => '2.00000000',
            'limit_price' => '100.00000000',
            'status' => 'PENDING',
            'idempotency_key' => 'failure-test-001',
            'request_hash' => hash('sha256', 'failure-test-001'),
        ]);

        LedgerEntry::create([
            'wallet_id' => $wallet->id,
            'order_id' => $order->id,
            'type' => 'RESERVATION',
            'amount' => '-200.00000000',
            'reserved_amount' => '200.00000000',
            'balance_after' => '9800.00000000',
            'reserved_balance_after' => '200.00000000',
        ]);

        $service = app(FailOrderService::class);

        $service->fail($order->id, 'Simulated permanent failure');

        $this->assertSame(
            OrderStatus::FAILED,
            $order->fresh()->status
        );

        $this->assertSame(
            'Simulated permanent failure',
            $order->fresh()->failure_reason
        );

        $wallet->refresh();

        $this->assertSame('10000.00000000', $wallet->available_balance);
        $this->assertSame('0.00000000', $wallet->reserved_balance);
        $this->assertDatabaseCount('ledger_entries', 2);

        // Calling the handler again must not release funds again.
        $service->fail($order->id, 'Repeated failure');

        $wallet->refresh();

        $this->assertSame('10000.00000000', $wallet->available_balance);
        $this->assertSame('0.00000000', $wallet->reserved_balance);
        $this->assertDatabaseCount('ledger_entries', 2);
    }
}
