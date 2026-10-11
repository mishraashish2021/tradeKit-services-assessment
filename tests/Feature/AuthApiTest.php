<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuthApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_returns_a_sanctum_token_for_valid_credentials(): void
    {
        $user = User::factory()->create(['email' => 'login@example.com', 'password' => Hash::make('correct-password')]);
        $this->postJson('/api/v1/login', ['email' => $user->email, 'password' => 'correct-password'])
            ->assertOk()
            ->assertJsonPath('token_type', 'Bearer')
            ->assertJsonPath('user.email', 'login@example.com')
            ->assertJsonStructure(['token', 'user' => ['id', 'name', 'email']]);
    }

    public function test_login_rejects_invalid_credentials_with_standard_error_shape(): void
    {
        $this->postJson('/api/v1/login', ['email' => 'missing@example.com', 'password' => 'wrong-password'])
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'invalid_credentials');
    }

    public function test_logout_requires_authentication_and_revokes_current_token(): void
    {
        $this->postJson('/api/v1/logout')->assertUnauthorized();

        $user = User::factory()->create();
        $plainToken = $user->createToken('test-token')->plainTextToken;
        $this->withToken($plainToken)->postJson('/api/v1/logout')->assertOk();
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }
}
