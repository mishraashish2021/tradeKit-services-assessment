<?php

namespace Tests\Unit;

use App\Jobs\ExecuteOrderJob;
use App\Models\Asset;
use App\Models\User;
use App\Models\Wallet;
use App\Services\PlaceBuyOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\TestCase;

class PlaceBuyOrderServiceTest extends TestCase
{
    use RefreshDatabase;

    private function createTradingSetup(): array
    {
        $user = User::factory()->create();

        $asset = Asset::factory()->create([
            'symbol' => 'DEMO',
            'name' => 'Demo Asset',
            'price_precision' => 2,
            'quantity_precision' => 8,
            'is_tradable' => true,
        ]);

        $wallet = Wallet::create([
            'user_id' => $user->id,
            'currency' => 'INR',
            'available_balance' => '10000.00000000',
            'reserved_balance' => '0.00000000',
        ]);

        return [$user, $asset, $wallet];
    }

    private function orderData(int $assetId, string $key): array
    {
        return [
            'asset_id' => $assetId,
            'side' => 'BUY',
            'type' => 'LIMIT',
            'quantity' => '2.00000000',
            'limit_price' => '100.00000000',
            'idempotency_key' => $key,
        ];
    }

    public function test_it_places_a_buy_limit_order_and_reserves_funds(): void
    {
        [$user, $asset, $wallet] = $this->createTradingSetup();

        $order = app(PlaceBuyOrderService::class)->place(
            $user->id,
            $this->orderData($asset->id, 'buy-demo-001')
        );

        $wallet->refresh();

        $this->assertSame($user->id, $order->user_id);
        $this->assertSame($asset->id, $order->asset_id);
        $this->assertSame('PENDING', $order->status->value);
        $this->assertSame('9800.00000000', $wallet->available_balance);
        $this->assertSame('200.00000000', $wallet->reserved_balance);
        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseCount('ledger_entries', 1);
    }

    public function test_same_idempotency_key_does_not_create_a_duplicate_order(): void
    {
        [$user, $asset] = $this->createTradingSetup();

        $service = app(PlaceBuyOrderService::class);
        $data = $this->orderData($asset->id, 'buy-demo-002');

        $first = $service->place($user->id, $data);
        $second = $service->place($user->id, $data);

        $this->assertSame($first->id, $second->id);
        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseCount('ledger_entries', 1);
    }

    public function test_reusing_key_with_different_details_is_rejected(): void
    {
        [$user, $asset] = $this->createTradingSetup();

        $service = app(PlaceBuyOrderService::class);
        $data = $this->orderData($asset->id, 'buy-demo-003');

        $service->place($user->id, $data);

        $changedData = $data;
        $changedData['quantity'] = '3.00000000';

        try {
            $service->place($user->id, $changedData);
            $this->fail('Expected the reused key to be rejected.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString(
                'different order details',
                $exception->getMessage()
            );
        }

        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseCount('ledger_entries', 1);
    }

    public function test_new_order_dispatches_execution_job_once(): void
    {
        Queue::fake();

        [$user, $asset] = $this->createTradingSetup();

        $service = app(PlaceBuyOrderService::class);
        $data = $this->orderData($asset->id, 'buy-queue-001');

        $order = $service->place($user->id, $data);

        Queue::assertPushed(
            ExecuteOrderJob::class,
            fn (ExecuteOrderJob $job) => $job->orderId === $order->id
        );

        // Repeating the same request must not dispatch another job.
        $service->place($user->id, $data);

        Queue::assertPushed(ExecuteOrderJob::class, 1);
    }
}
