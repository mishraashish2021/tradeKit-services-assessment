<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class WalletApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_view_wallet(): void
    {
        $this->getJson('/api/v1/wallet')->assertUnauthorized();
    }

    public function test_authenticated_user_can_view_own_wallet(): void
    {
        $user = User::factory()->create();

        Wallet::create([
            'user_id' => $user->id,
            'currency' => 'INR',
            'available_balance' => '5000.00000000',
            'reserved_balance' => '1000.00000000',
        ]);

        Sanctum::actingAs($user);

        $this->getJson('/api/v1/wallet')
            ->assertOk()
            ->assertJsonPath('data.0.currency', 'INR')
            ->assertJsonPath('data.0.available_balance', '5000.00000000')
            ->assertJsonPath('data.0.reserved_balance', '1000.00000000');
    }
}
