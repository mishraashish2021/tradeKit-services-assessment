<?php

namespace Tests\Unit;

use App\Models\User;
use App\Models\Wallet;
use App\Services\WalletLedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class WalletLedgerServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_reservation_moves_available_funds_to_reserved(): void
    {
        $user = User::factory()->create();

        $wallet = Wallet::create([
            'user_id' => $user->id,
            'currency' => 'INR',
            'available_balance' => '10000.00000000',
            'reserved_balance' => '0.00000000',
        ]);

        app(WalletLedgerService::class)->reserve(
            $wallet->id,
            '2000.00000000'
        );

        $wallet->refresh();

        $this->assertSame('8000.00000000', $wallet->available_balance);
        $this->assertSame('2000.00000000', $wallet->reserved_balance);
        $this->assertDatabaseHas('ledger_entries', [
            'wallet_id' => $wallet->id,
            'type' => 'RESERVATION',
            'amount' => '-2000.00000000',
            'reserved_amount' => '2000.00000000',
        ]);
    }

    public function test_release_restores_available_funds(): void
    {
        $user = User::factory()->create();

        $wallet = Wallet::create([
            'user_id' => $user->id,
            'currency' => 'INR',
            'available_balance' => '8000.00000000',
            'reserved_balance' => '2000.00000000',
        ]);

        app(WalletLedgerService::class)->release(
            $wallet->id,
            '2000.00000000'
        );

        $wallet->refresh();

        $this->assertSame('10000.00000000', $wallet->available_balance);
        $this->assertSame('0.00000000', $wallet->reserved_balance);
        $this->assertDatabaseHas('ledger_entries', [
            'wallet_id' => $wallet->id,
            'type' => 'RELEASE',
            'amount' => '2000.00000000',
            'reserved_amount' => '-2000.00000000',
        ]);
    }

    public function test_reservation_fails_when_funds_are_insufficient(): void
    {
        $user = User::factory()->create();

        $wallet = Wallet::create([
            'user_id' => $user->id,
            'currency' => 'INR',
            'available_balance' => '100.00000000',
            'reserved_balance' => '0.00000000',
        ]);

        try {
            app(WalletLedgerService::class)->reserve(
                $wallet->id,
                '200.00000000'
            );

            $this->fail('Expected reservation to fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame(
                'Insufficient available balance.',
                $exception->getMessage()
            );
        }

        $wallet->refresh();

        $this->assertSame('100.00000000', $wallet->available_balance);
        $this->assertSame('0.00000000', $wallet->reserved_balance);
        $this->assertDatabaseCount('ledger_entries', 0);
    }
}
