<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\LedgerEntry;
use App\Models\Order;
use App\Models\User;
use App\Models\Wallet;
use App\Services\PlaceBuyOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class OrderApiTest extends TestCase
{
    use RefreshDatabase;

    private function createTradingUser(): array
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

    private function validOrderData(int $assetId): array
    {
        return [
            'asset_id' => $assetId,
            'side' => 'BUY',
            'type' => 'LIMIT',
            'quantity' => '2.00000000',
            'limit_price' => '100.00000000',
            'idempotency_key' => 'api-buy-001',
        ];
    }

    public function test_guest_cannot_place_an_order(): void
    {
        [, $asset] = $this->createTradingUser();

        $this->postJson(
            '/api/v1/orders',
            $this->validOrderData($asset->id)
        )->assertUnauthorized();
    }

    public function test_authenticated_user_can_place_a_buy_order(): void
    {
        [$user, $asset, $wallet] = $this->createTradingUser();

        Sanctum::actingAs($user);

        $response = $this->withHeader('Idempotency-Key', 'api-buy-001')->postJson(
            '/api/v1/orders',
            $this->validOrderData($asset->id)
        );

        $response
            ->assertStatus(202)
            ->assertJsonPath('message', 'Order accepted.')
            ->assertJsonPath('data.side', 'BUY')
            ->assertJsonPath('data.status', 'PENDING');

        $wallet->refresh();

        $this->assertSame(
            '9800.00000000',
            $wallet->available_balance
        );

        $this->assertSame(
            '200.00000000',
            $wallet->reserved_balance
        );

        $this->assertDatabaseCount('orders', 1);
    }

    public function test_same_idempotency_header_replays_original_order_and_different_payload_conflicts(): void
    {
        [$user, $asset] = $this->createTradingUser();
        Sanctum::actingAs($user);
        $headers = ['Idempotency-Key' => 'api-idempotency-001'];
        $first = $this->withHeaders($headers)->postJson('/api/v1/orders', $this->validOrderData($asset->id));
        $first->assertStatus(202);
        $orderId = $first->json('data.id');

        $replay = $this->withHeaders($headers)->postJson('/api/v1/orders', $this->validOrderData($asset->id));
        $replay->assertStatus(202)->assertJsonPath('data.id', $orderId);
        $this->assertDatabaseCount('orders', 1);

        $different = $this->validOrderData($asset->id);
        $different['quantity'] = '3.00000000';
        $this->withHeaders($headers)->postJson('/api/v1/orders', $different)
            ->assertConflict()
            ->assertJsonPath('error.code', 'idempotency_conflict');
    }

    public function test_user_cannot_view_another_users_order(): void
    {
        [$owner, $asset] = $this->createTradingUser();

        $order = app(PlaceBuyOrderService::class)->place(
            $owner->id,
            $this->validOrderData($asset->id)
        );

        $otherUser = User::factory()->create();

        Sanctum::actingAs($otherUser);

        $this->getJson('/api/v1/orders/'.$order->id)
            ->assertNotFound();
    }

    public function test_user_sees_only_their_own_orders(): void
    {
        [$owner, $asset] = $this->createTradingUser();

        app(PlaceBuyOrderService::class)->place(
            $owner->id,
            $this->validOrderData($asset->id)
        );

        $otherUser = User::factory()->create();

        Sanctum::actingAs($otherUser);

        $this->getJson('/api/v1/orders')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_user_can_cancel_pending_buy_order_and_restore_wallet_balance(): void
    {
        [$user, $asset, $wallet] = $this->createTradingUser();

        $order = app(PlaceBuyOrderService::class)->place(
            $user->id,
            $this->validOrderData($asset->id)
        );

        Sanctum::actingAs($user);

        $this->deleteJson('/api/v1/orders/'.$order->id)
            ->assertOk()
            ->assertJsonPath('message', 'Order cancelled successfully.')
            ->assertJsonPath('data.status', 'CANCELLED');

        $wallet->refresh();

        $this->assertSame('10000.00000000', $wallet->available_balance);
        $this->assertSame('0.00000000', $wallet->reserved_balance);
    }

    public function test_cancelling_same_order_twice_does_not_release_funds_twice(): void
    {
        [$user, $asset, $wallet] = $this->createTradingUser();

        $order = app(PlaceBuyOrderService::class)->place(
            $user->id,
            $this->validOrderData($asset->id)
        );

        Sanctum::actingAs($user);

        $this->deleteJson('/api/v1/orders/'.$order->id)->assertOk();

        // A repeated cancellation should be safe and successful.
        $this->deleteJson('/api/v1/orders/'.$order->id)
            ->assertOk()
            ->assertJsonPath('data.status', 'CANCELLED');

        $wallet->refresh();

        $this->assertSame('10000.00000000', $wallet->available_balance);
        $this->assertSame('0.00000000', $wallet->reserved_balance);

        $this->assertDatabaseCount('ledger_entries', 2);
    }

    public function test_user_cannot_cancel_another_users_order(): void
    {
        [$owner, $asset] = $this->createTradingUser();

        $order = app(PlaceBuyOrderService::class)->place(
            $owner->id,
            $this->validOrderData($asset->id)
        );

        $otherUser = User::factory()->create();

        Sanctum::actingAs($otherUser);

        $this->deleteJson('/api/v1/orders/'.$order->id)
            ->assertNotFound();

        $this->assertSame('PENDING', $order->fresh()->status->value);
    }

    public function test_filled_order_cannot_be_cancelled_or_refunded_again(): void
    {
        $user = User::factory()->create();

        $asset = Asset::factory()->create([
            'symbol' => 'RACE',
            'is_tradable' => true,
        ]);

        $wallet = Wallet::create([
            'user_id' => $user->id,
            'currency' => 'INR',
            'available_balance' => '9820.00000000',
            'reserved_balance' => '0.00000000',
        ]);

        $order = Order::create([
            'uuid' => (string) str()->uuid(),
            'user_id' => $user->id,
            'asset_id' => $asset->id,
            'side' => 'BUY',
            'type' => 'LIMIT',
            'quantity' => '2.00000000',
            'limit_price' => '100.00000000',
            'status' => 'FILLED',
            'idempotency_key' => 'filled-cancel-test-001',
            'request_hash' => hash('sha256', 'filled-cancel-test-001'),
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

        $this->actingAs($user);

        $response = $this->deleteJson("/api/v1/orders/{$order->id}");

        $response->assertConflict();

        $this->assertSame('FILLED', $order->fresh()->status->value);

        $wallet->refresh();

        $this->assertSame('9820.00000000', $wallet->available_balance);
        $this->assertSame('0.00000000', $wallet->reserved_balance);
    }
}
