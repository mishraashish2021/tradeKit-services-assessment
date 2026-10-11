<?php

namespace Tests\Unit;

use App\Enums\OrderStatus;
use App\Jobs\ExecuteOrderJob;
use App\Models\Asset;
use App\Models\Execution;
use App\Models\LedgerEntry;
use App\Models\MarketTick;
use App\Models\Order;
use App\Models\Position;
use App\Models\User;
use App\Models\Wallet;
use App\Services\ExecuteBuyOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class ExecuteBuyOrderServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_buy_order_executes_once_and_refunds_unused_reservation(): void
    {
        $user = User::factory()->create();

        $asset = Asset::factory()->create([
            'symbol' => 'TEST',
            'price_precision' => 2,
            'quantity_precision' => 8,
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
            'idempotency_key' => 'execute-test-001',
            'request_hash' => hash('sha256', 'execute-test-001'),
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

        MarketTick::create([
            'asset_id' => $asset->id,
            'price' => '90.00000000',
            'recorded_at' => now(),
        ]);
        Cache::put("market:latest:{$asset->id}", '90.00000000', 60);

        $service = app(ExecuteBuyOrderService::class);

        $service->execute($order->id);

        $this->assertSame(
            OrderStatus::FILLED,
            $order->fresh()->status
        );

        $wallet->refresh();

        // Execution cost = 2 × 90 = 180; refund = 200 − 180 = 20.
        $this->assertSame('9820.00000000', $wallet->available_balance);
        $this->assertSame('0.00000000', $wallet->reserved_balance);

        $this->assertDatabaseCount('executions', 1);
        $this->assertDatabaseCount('ledger_entries', 2);

        $position = Position::query()
            ->where('user_id', $user->id)
            ->where('asset_id', $asset->id)
            ->firstOrFail();

        $this->assertSame('2.00000000', $position->quantity);
        $this->assertSame('90.00000000', $position->average_cost);

        // Simulate the same job being run again.
        $service->execute($order->id);

        $this->assertDatabaseCount('executions', 1);
        $this->assertDatabaseCount('ledger_entries', 2);

        $wallet->refresh();

        $this->assertSame('9820.00000000', $wallet->available_balance);
        $this->assertSame('0.00000000', $wallet->reserved_balance);
    }

    public function test_running_execution_job_twice_does_not_duplicate_execution(): void
    {
        $user = User::factory()->create();

        $asset = Asset::factory()->create([
            'symbol' => 'JOBTEST',
            'price_precision' => 2,
            'quantity_precision' => 8,
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
            'idempotency_key' => 'job-retry-test-001',
            'request_hash' => hash('sha256', 'job-retry-test-001'),
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

        MarketTick::create([
            'asset_id' => $asset->id,
            'price' => '90.00000000',
            'recorded_at' => now(),
        ]);
        Cache::put("market:latest:{$asset->id}", '90.00000000', 60);

        $job = new ExecuteOrderJob($order->id);

        $job->handle(app(ExecuteBuyOrderService::class));
        $job->handle(app(ExecuteBuyOrderService::class));

        $this->assertSame(OrderStatus::FILLED, $order->fresh()->status);
        $this->assertDatabaseCount('executions', 1);
        $this->assertDatabaseCount('ledger_entries', 2);

        $wallet->refresh();

        $this->assertSame('9820.00000000', $wallet->available_balance);
        $this->assertSame('0.00000000', $wallet->reserved_balance);
    }
}
