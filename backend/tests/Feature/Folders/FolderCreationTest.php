<?php

declare(strict_types=1);

namespace Tests\Feature\Folders;

use App\Models\EmailAccount;
use App\Models\Folder;
use App\Models\User;
use Database\Seeders\MailServerSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;
use Webklex\PHPIMAP\Client;
use Webklex\PHPIMAP\ClientManager;
use Webklex\PHPIMAP\Exceptions\AuthFailedException;
use Webklex\PHPIMAP\Folder as ImapFolder;

class FolderCreationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(MailServerSeeder::class);
    }

    private function mockSuccessfulCreate(string $expectedName): void
    {
        $imapFolder = Mockery::mock(ImapFolder::class);

        $client = Mockery::mock(Client::class);
        $client->shouldReceive('connect')->andReturnSelf();
        $client->shouldReceive('disconnect')->andReturnSelf();
        $client->shouldReceive('createFolder')->with($expectedName)->once()->andReturn($imapFolder);

        $this->mock(ClientManager::class, function (MockInterface $mock) use ($client) {
            $mock->shouldReceive('make')->andReturn($client);
        });
    }

    public function test_creating_a_folder_persists_it_locally_as_custom(): void
    {
        $this->mockSuccessfulCreate('Projects');

        $user = User::factory()->create();
        $account = EmailAccount::factory()->for($user)->create();

        $response = $this->actingAs($user, 'sanctum')->postJson("/api/email-accounts/{$account->id}/folders", [
            'name' => 'Projects',
        ]);

        $response->assertCreated();
        $response->assertJsonPath('name', 'Projects');
        $response->assertJsonPath('type', 'custom');
        $response->assertJsonPath('display_name', 'Projects');

        $this->assertDatabaseHas('folders', [
            'email_account_id' => $account->id,
            'name' => 'Projects',
            'type' => 'custom',
        ]);
    }

    public function test_creating_a_folder_with_a_duplicate_name_is_rejected(): void
    {
        $user = User::factory()->create();
        $account = EmailAccount::factory()->for($user)->create();
        Folder::factory()->for($account)->create(['name' => 'Projects']);

        $response = $this->actingAs($user, 'sanctum')->postJson("/api/email-accounts/{$account->id}/folders", [
            'name' => 'Projects',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['name']);
    }

    public function test_creating_a_folder_fails_gracefully_on_imap_auth_failure(): void
    {
        $client = Mockery::mock(Client::class);
        $client->shouldReceive('connect')->andThrow(new AuthFailedException);
        $client->shouldReceive('disconnect')->andReturnSelf();

        $this->mock(ClientManager::class, function (MockInterface $mock) use ($client) {
            $mock->shouldReceive('make')->andReturn($client);
        });

        $user = User::factory()->create();
        $account = EmailAccount::factory()->for($user)->create(['status' => 'active']);

        $response = $this->actingAs($user, 'sanctum')->postJson("/api/email-accounts/{$account->id}/folders", [
            'name' => 'Projects',
        ]);

        $response->assertStatus(422);
        $this->assertSame('needs_reauth', $account->fresh()->status);
        $this->assertDatabaseMissing('folders', ['name' => 'Projects']);
    }

    public function test_a_user_cannot_create_a_folder_for_another_users_account(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $account = EmailAccount::factory()->for($other)->create();

        $response = $this->actingAs($user, 'sanctum')->postJson("/api/email-accounts/{$account->id}/folders", [
            'name' => 'Projects',
        ]);

        $response->assertForbidden();
    }
}
