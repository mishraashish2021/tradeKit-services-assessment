<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\DatabaseMigrations;
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
            $workers[] = new Process([
                PHP_BINARY,
                base_path('tests/Support/concurrent_buy_worker.php'),
                (string) $user->id,
                (string) $asset->id,
                "concurrent-buy-{$i}",
            ], base_path());
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
            } else {
                $workerFailures[] = sprintf(
                    "Worker %d failed (exit code: %s)\nSTDOUT: %s\nSTDERR: %s",
                    $index + 1,
                    (string) $worker->getExitCode(),
                    $worker->getOutput(),
                    $worker->getErrorOutput(),
                );
            }
        }

        $this->assertSame(
            20,
            count($workers),
            'Expected all 20 worker processes to be created.'
        );

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

        $this->assertSame(5, $successCount);
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
}
