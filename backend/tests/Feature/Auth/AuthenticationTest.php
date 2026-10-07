<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_user_can_register_and_receives_a_token(): void
    {
        $response = $this->postJson('/api/register', [
            'name' => 'Ali Rezaei',
            'username' => 'alirezaei',
            'email' => 'ali@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response->assertCreated()
            ->assertJsonStructure(['user' => ['id', 'name', 'username', 'email'], 'token']);

        $this->assertDatabaseHas('users', ['username' => 'alirezaei']);
    }

    public function test_registration_requires_a_unique_username(): void
    {
        User::factory()->create(['username' => 'taken']);

        $response = $this->postJson('/api/register', [
            'name' => 'Someone',
            'username' => 'taken',
            'email' => 'someone@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response->assertUnprocessable()->assertJsonValidationErrors('username');
    }

    public function test_a_user_can_login_with_correct_credentials(): void
    {
        $user = User::factory()->create([
            'username' => 'alirezaei',
            'password' => 'password123',
        ]);

        $response = $this->postJson('/api/login', [
            'username' => 'alirezaei',
            'password' => 'password123',
        ]);

        $response->assertOk()->assertJsonStructure(['user', 'token']);
        $this->assertSame($user->id, $response->json('user.id'));
    }

    public function test_login_fails_with_incorrect_password(): void
    {
        User::factory()->create([
            'username' => 'alirezaei',
            'password' => 'password123',
        ]);

        $response = $this->postJson('/api/login', [
            'username' => 'alirezaei',
            'password' => 'wrong-password',
        ]);

        $response->assertUnprocessable()->assertJsonValidationErrors('username');
    }

    public function test_an_authenticated_user_can_logout(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('api')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/logout');

        $response->assertNoContent();
    }

    public function test_email_accounts_endpoint_requires_authentication(): void
    {
        $this->getJson('/api/email-accounts')->assertUnauthorized();
    }
}
