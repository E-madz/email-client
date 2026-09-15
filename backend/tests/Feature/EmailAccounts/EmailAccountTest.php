<?php

declare(strict_types=1);

namespace Tests\Feature\EmailAccounts;

use App\Models\EmailAccount;
use App\Models\MailServer;
use App\Models\User;
use Database\Seeders\MailServerSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\TestCase;
use Webklex\PHPIMAP\Client;
use Webklex\PHPIMAP\ClientManager;
use Webklex\PHPIMAP\Exceptions\AuthFailedException;
use Webklex\PHPIMAP\Exceptions\ConnectionFailedException;

class EmailAccountTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(MailServerSeeder::class);
    }

    private function mockImapHandshake(bool $succeeds, ?\Throwable $failureException = null): void
    {
        $client = $this->mock(Client::class, function (MockInterface $mock) use ($succeeds, $failureException) {
            if ($succeeds) {
                $mock->shouldReceive('connect')->once()->andReturnSelf();
            } else {
                $mock->shouldReceive('connect')->once()->andThrow($failureException);
            }
            $mock->shouldReceive('disconnect')->once()->andReturnSelf();
        });

        $this->mock(ClientManager::class, function (MockInterface $mock) use ($client) {
            $mock->shouldReceive('make')->once()->andReturn($client);
        });
    }

    public function test_a_user_can_connect_a_verified_email_account(): void
    {
        $this->mockImapHandshake(succeeds: true);
        $user = User::factory()->create();

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/email-accounts', [
            'email' => 'user@mycompany.ir',
            'password' => 'super-secret',
        ]);

        $response->assertCreated()
            ->assertJsonPath('email', 'user@mycompany.ir')
            ->assertJsonPath('status', 'active')
            ->assertJsonMissing(['password']);

        $this->assertDatabaseHas('email_accounts', [
            'user_id' => $user->id,
            'email' => 'user@mycompany.ir',
            'status' => 'active',
        ]);

        $stored = EmailAccount::first();
        $this->assertSame('super-secret', $stored->password);
    }

    public function test_connecting_an_account_fails_with_wrong_credentials_and_nothing_is_stored(): void
    {
        $this->mockImapHandshake(succeeds: false, failureException: new AuthFailedException);
        $user = User::factory()->create();

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/email-accounts', [
            'email' => 'user@mycompany.ir',
            'password' => 'wrong-password',
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseCount('email_accounts', 0);
    }

    public function test_connecting_an_account_reports_a_distinct_error_when_the_server_is_unreachable(): void
    {
        $this->mockImapHandshake(succeeds: false, failureException: new ConnectionFailedException);
        $user = User::factory()->create();

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/email-accounts', [
            'email' => 'user@mycompany.ir',
            'password' => 'super-secret',
        ]);

        $response->assertStatus(503);
        $this->assertDatabaseCount('email_accounts', 0);
    }

    public function test_a_user_cannot_add_the_same_email_twice(): void
    {
        $user = User::factory()->create();
        EmailAccount::factory()->for($user)->create(['email' => 'dup@mycompany.ir']);

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/email-accounts', [
            'email' => 'dup@mycompany.ir',
            'password' => 'super-secret',
        ]);

        $response->assertUnprocessable()->assertJsonValidationErrors('email');
    }

    public function test_a_user_only_sees_their_own_email_accounts(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        EmailAccount::factory()->for($user)->create();
        EmailAccount::factory()->for($other)->create();

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/email-accounts');

        $response->assertOk()->assertJsonCount(1);
    }

    public function test_updating_with_the_new_correct_password_clears_needs_reauth(): void
    {
        $this->mockImapHandshake(succeeds: true);
        $user = User::factory()->create();
        $account = EmailAccount::factory()->for($user)->create(['status' => 'needs_reauth']);

        $response = $this->actingAs($user, 'sanctum')->putJson("/api/email-accounts/{$account->id}", [
            'password' => 'new-correct-password',
        ]);

        $response->assertOk()->assertJsonPath('status', 'active');
        $this->assertSame('active', $account->fresh()->status);
        $this->assertSame('new-correct-password', $account->fresh()->password);
    }

    public function test_updating_with_a_still_wrong_password_marks_needs_reauth(): void
    {
        $this->mockImapHandshake(succeeds: false, failureException: new AuthFailedException);
        $user = User::factory()->create();
        $account = EmailAccount::factory()->for($user)->create(['status' => 'active']);

        $response = $this->actingAs($user, 'sanctum')->putJson("/api/email-accounts/{$account->id}", [
            'password' => 'still-wrong',
        ]);

        $response->assertStatus(422);
        $this->assertSame('needs_reauth', $account->fresh()->status);
    }

    public function test_a_user_cannot_update_another_users_email_account(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $account = EmailAccount::factory()->for($other)->create();

        $response = $this->actingAs($user, 'sanctum')->putJson("/api/email-accounts/{$account->id}", [
            'password' => 'whatever',
        ]);

        $response->assertForbidden();
    }

    public function test_a_user_can_hard_delete_their_own_email_account(): void
    {
        $user = User::factory()->create();
        $account = EmailAccount::factory()->for($user)->create();

        $response = $this->actingAs($user, 'sanctum')->deleteJson("/api/email-accounts/{$account->id}");

        $response->assertNoContent();
        $this->assertDatabaseMissing('email_accounts', ['id' => $account->id]);
    }

    public function test_a_user_cannot_delete_another_users_email_account(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $account = EmailAccount::factory()->for($other)->create();

        $response = $this->actingAs($user, 'sanctum')->deleteJson("/api/email-accounts/{$account->id}");

        $response->assertForbidden();
        $this->assertDatabaseHas('email_accounts', ['id' => $account->id]);
    }

    public function test_deleting_a_mail_server_with_connected_accounts_is_restricted(): void
    {
        $mailServer = MailServer::query()->where('slug', 'iranserver')->firstOrFail();
        EmailAccount::factory()->create(['mail_server_id' => $mailServer->id]);

        $this->expectException(QueryException::class);

        $mailServer->delete();
    }
}
