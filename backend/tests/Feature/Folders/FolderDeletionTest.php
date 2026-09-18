<?php

declare(strict_types=1);

namespace Tests\Feature\Folders;

use App\Models\EmailAccount;
use App\Models\Folder;
use App\Models\Message;
use App\Models\User;
use Database\Seeders\MailServerSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;
use Webklex\PHPIMAP\Client;
use Webklex\PHPIMAP\ClientManager;
use Webklex\PHPIMAP\Exceptions\AuthFailedException;

class FolderDeletionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(MailServerSeeder::class);
    }

    private function mockSuccessfulDelete(string $expectedName): void
    {
        $client = Mockery::mock(Client::class);
        $client->shouldReceive('connect')->andReturnSelf();
        $client->shouldReceive('disconnect')->andReturnSelf();
        $client->shouldReceive('deleteFolder')->with($expectedName, false)->once()->andReturn([]);

        $this->mock(ClientManager::class, function (MockInterface $mock) use ($client) {
            $mock->shouldReceive('make')->andReturn($client);
        });
    }

    public function test_deleting_a_custom_folder_removes_it_locally(): void
    {
        $this->mockSuccessfulDelete('Projects');

        $user = User::factory()->create();
        $account = EmailAccount::factory()->for($user)->create();
        $folder = Folder::factory()->for($account)->create(['name' => 'Projects', 'type' => 'custom']);

        $response = $this->actingAs($user, 'sanctum')->deleteJson("/api/folders/{$folder->id}");

        $response->assertNoContent();
        $this->assertDatabaseMissing('folders', ['id' => $folder->id]);
    }

    public function test_deleting_a_non_empty_custom_folder_also_removes_its_messages_locally(): void
    {
        $this->mockSuccessfulDelete('Projects');

        $user = User::factory()->create();
        $account = EmailAccount::factory()->for($user)->create();
        $folder = Folder::factory()->for($account)->create(['name' => 'Projects', 'type' => 'custom']);
        $message = Message::factory()->for($folder)->create();

        $response = $this->actingAs($user, 'sanctum')->deleteJson("/api/folders/{$folder->id}");

        $response->assertNoContent();
        $this->assertDatabaseMissing('folders', ['id' => $folder->id]);
        $this->assertDatabaseMissing('messages', ['id' => $message->id]);
    }

    public function test_a_system_folder_cannot_be_deleted(): void
    {
        $user = User::factory()->create();
        $account = EmailAccount::factory()->for($user)->create();
        $inbox = Folder::factory()->for($account)->create(['name' => 'INBOX', 'type' => 'inbox']);

        $response = $this->actingAs($user, 'sanctum')->deleteJson("/api/folders/{$inbox->id}");

        $response->assertStatus(422);
        $this->assertDatabaseHas('folders', ['id' => $inbox->id]);
    }

    public function test_folder_deletion_fails_gracefully_on_imap_auth_failure(): void
    {
        $client = Mockery::mock(Client::class);
        $client->shouldReceive('connect')->andThrow(new AuthFailedException);
        $client->shouldReceive('disconnect')->andReturnSelf();

        $this->mock(ClientManager::class, function (MockInterface $mock) use ($client) {
            $mock->shouldReceive('make')->andReturn($client);
        });

        $user = User::factory()->create();
        $account = EmailAccount::factory()->for($user)->create(['status' => 'active']);
        $folder = Folder::factory()->for($account)->create(['name' => 'Projects', 'type' => 'custom']);

        $response = $this->actingAs($user, 'sanctum')->deleteJson("/api/folders/{$folder->id}");

        $response->assertStatus(422);
        $this->assertSame('needs_reauth', $account->fresh()->status);
        $this->assertDatabaseHas('folders', ['id' => $folder->id]);
    }

    public function test_a_user_cannot_delete_another_users_folder(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $account = EmailAccount::factory()->for($other)->create();
        $folder = Folder::factory()->for($account)->create(['name' => 'Projects', 'type' => 'custom']);

        $response = $this->actingAs($user, 'sanctum')->deleteJson("/api/folders/{$folder->id}");

        $response->assertForbidden();
        $this->assertDatabaseHas('folders', ['id' => $folder->id]);
    }
}
