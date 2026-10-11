<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\LedgerEntry;
use App\Models\MarketTick;
use App\Models\Order;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class ConcurrentBuyOrderTest extends TestCase
{
    use DatabaseMigrations;

    public function test_concurrent_buy_orders_cannot_overspend_wallet(): void
    {
        $this->assertSame(
            'trading_order_system_test',
            config('database.connections.mysql.database')
        );

        $user = User::factory()->create();

        $asset = Asset::factory()->create([
            'symbol' => 'RACE',
            'name' => 'Concurrency Test Asset',
            'is_tradable' => true,
        ]);

        Wallet::create([
            'user_id' => $user->id,
            'currency' => 'INR',
            'available_balance' => '1000.00000000',
            'reserved_balance' => '0.00000000',
        ]);

        $workers = [];

        for ($i = 1; $i <= 20; $i++) {
            $workers[] = new Process(
                [
                    PHP_BINARY,
                    base_path('tests/Support/concurrent_buy_worker.php'),
                    (string) $user->id,
                    (string) $asset->id,
                    "concurrent-buy-{$i}",
                ],
                base_path(),
                [
                    'APP_ENV' => 'testing',
                    'DB_CONNECTION' => 'mysql',
                    'DB_HOST' => getenv('DB_HOST') ?: '127.0.0.1',
                    'DB_PORT' => getenv('GITHUB_ACTIONS') === 'true' ? '3306' : '3307',
                    'DB_DATABASE' => 'trading_order_system_test',
                    'DB_USERNAME' => getenv('DB_USERNAME') ?: 'laravel',
                    'DB_PASSWORD' => getenv('DB_PASSWORD') ?: 'laravel_dev_password',
                ]
            );
        }

        // Start all workers before waiting for any one worker.
        foreach ($workers as $worker) {
            $worker->start();
        }

        foreach ($workers as $worker) {
            $worker->wait();
        }

        $successCount = 0;
        $workerFailures = [];

        foreach ($workers as $index => $worker) {
            if ($worker->isSuccessful()) {
                $successCount++;

                continue;
            }

            $workerFailures[] = sprintf(
                "Worker %d failed (exit code: %s)\nSTDOUT: %s\nSTDERR: %s",
                $index + 1,
                (string) $worker->getExitCode(),
                $worker->getOutput(),
                $worker->getErrorOutput(),
            );
        }

        $this->assertSame(
            5,
            $successCount,
            "Expected 5 successful orders. Worker failures:\n".
            implode("\n---\n", $workerFailures),
        );

        $wallet = Wallet::query()
            ->where('user_id', $user->id)
            ->where('currency', 'INR')
            ->firstOrFail();

        $this->assertSame(
            '0.00000000',
            $wallet->fresh()->available_balance
        );

        $this->assertSame(
            '1000.00000000',
            $wallet->fresh()->reserved_balance
        );

        $this->assertSame(
            5,
            DB::table('orders')
                ->where('user_id', $user->id)
                ->count()
        );

        $this->assertSame(
            5,
            DB::table('ledger_entries')
                ->where('wallet_id', $wallet->id)
                ->count()
        );
    }

    public function test_concurrent_cancellation_and_execution_preserve_order_and_wallet_consistency(): void
    {
        $this->assertSame(
            'trading_order_system_test',
            config('database.connections.mysql.database')
        );

        $user = User::factory()->create();

        $asset = Asset::factory()->create([
            'symbol' => 'CANCELRACE',
            'name' => 'Cancel Execution Race Asset',
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
            'idempotency_key' => 'cancel-execute-race-001',
            'request_hash' => hash('sha256', 'cancel-execute-race-001'),
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
        Cache::put("market:latest_at:{$asset->id}", now()->timestamp, 60);

        $environment = [
            'APP_ENV' => 'testing',
            'DB_CONNECTION' => 'mysql',
            'DB_HOST' => getenv('DB_HOST') ?: '127.0.0.1',
            'DB_PORT' => getenv('GITHUB_ACTIONS') === 'true' ? '3306' : '3307',
            'DB_DATABASE' => 'trading_order_system_test',
            'DB_USERNAME' => getenv('DB_USERNAME') ?: 'laravel',
            'DB_PASSWORD' => getenv('DB_PASSWORD') ?: 'laravel_dev_password',
        ];

        $cancel = new Process(
            [
                PHP_BINARY,
                base_path('tests/Support/cancel_execute_race_worker.php'),
                'cancel',
                (string) $order->id,
                (string) $user->id,
            ],
            base_path(),
            $environment
        );

        $execute = new Process(
            [
                PHP_BINARY,
                base_path('tests/Support/cancel_execute_race_worker.php'),
                'execute',
                (string) $order->id,
                (string) $user->id,
            ],
            base_path(),
            $environment
        );

        // Start both processes before waiting for either one.
        $cancel->start();
        $execute->start();

        $cancel->wait();
        $execute->wait();

        $this->assertTrue(
            $cancel->isSuccessful(),
            "Cancellation worker failed:\n".$cancel->getErrorOutput()
        );

        $this->assertTrue(
            $execute->isSuccessful(),
            "Execution worker failed:\n".$execute->getErrorOutput()
        );

        $order->refresh();
        $wallet->refresh();

        $this->assertContains(
            $order->status->value,
            ['CANCELLED', 'FILLED']
        );

        if ($order->status->value === 'CANCELLED') {
            $this->assertDatabaseCount('executions', 0);
            $this->assertSame('10000.00000000', $wallet->available_balance);
            $this->assertSame('0.00000000', $wallet->reserved_balance);
            $this->assertSame(
                2,
                LedgerEntry::query()->where('order_id', $order->id)->count()
            );
        } else {
            $this->assertDatabaseCount('executions', 1);
            $this->assertSame('9820.00000000', $wallet->available_balance);
            $this->assertSame('0.00000000', $wallet->reserved_balance);
            $this->assertSame(
                2,
                LedgerEntry::query()->where('order_id', $order->id)->count()
            );
        }
    }
}
