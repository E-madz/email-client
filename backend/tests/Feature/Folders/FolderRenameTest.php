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

class FolderRenameTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(MailServerSeeder::class);
    }

    private function mockSuccessfulRename(string $currentName, string $newName): void
    {
        // Positional args in with(), not named (expunge: false) — a known
        // Mockery quirk in this codebase's version throws a cryptic
        // "Undefined array key 0" for named arguments in with() on some
        // methods (see e.g. InteractsWithImapMocks's own history).
        $imapFolder = Mockery::mock(ImapFolder::class);
        $imapFolder->shouldReceive('rename')->with($newName, false)->once()->andReturn([]);

        $client = Mockery::mock(Client::class);
        $client->shouldReceive('connect')->andReturnSelf();
        $client->shouldReceive('disconnect')->andReturnSelf();
        $client->shouldReceive('getFolderByPath')->with($currentName)->andReturn($imapFolder);

        $this->mock(ClientManager::class, function (MockInterface $mock) use ($client) {
            $mock->shouldReceive('make')->andReturn($client);
        });
    }

    public function test_renaming_a_custom_folder_updates_its_name_and_display_name_locally(): void
    {
        $this->mockSuccessfulRename('Projects', 'Work');

        $user = User::factory()->create();
        $account = EmailAccount::factory()->for($user)->create();
        $folder = Folder::factory()->for($account)->create([
            'name' => 'Projects',
            'display_name' => 'Projects',
            'type' => 'custom',
        ]);

        $response = $this->actingAs($user, 'sanctum')->patchJson("/api/folders/{$folder->id}", [
            'name' => 'Work',
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('folders', [
            'id' => $folder->id,
            'name' => 'Work',
            'display_name' => 'Work',
        ]);
    }

    public function test_a_system_folder_cannot_be_renamed(): void
    {
        $user = User::factory()->create();
        $account = EmailAccount::factory()->for($user)->create();
        $inbox = Folder::factory()->for($account)->create(['name' => 'INBOX', 'type' => 'inbox']);

        $response = $this->actingAs($user, 'sanctum')->patchJson("/api/folders/{$inbox->id}", [
            'name' => 'My Inbox',
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseHas('folders', ['id' => $inbox->id, 'name' => 'INBOX']);
    }

    public function test_renaming_to_a_name_already_used_in_the_account_is_rejected(): void
    {
        $user = User::factory()->create();
        $account = EmailAccount::factory()->for($user)->create();
        Folder::factory()->for($account)->create(['name' => 'Archive', 'type' => 'custom']);
        $folder = Folder::factory()->for($account)->create(['name' => 'Projects', 'type' => 'custom']);

        $response = $this->actingAs($user, 'sanctum')->patchJson("/api/folders/{$folder->id}", [
            'name' => 'Archive',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['name']);
        $this->assertDatabaseHas('folders', ['id' => $folder->id, 'name' => 'Projects']);
    }

    public function test_renaming_a_folder_to_its_own_current_name_is_allowed(): void
    {
        $this->mockSuccessfulRename('Projects', 'Projects');

        $user = User::factory()->create();
        $account = EmailAccount::factory()->for($user)->create();
        $folder = Folder::factory()->for($account)->create(['name' => 'Projects', 'type' => 'custom']);

        $response = $this->actingAs($user, 'sanctum')->patchJson("/api/folders/{$folder->id}", [
            'name' => 'Projects',
        ]);

        $response->assertOk();
    }

    public function test_folder_rename_fails_gracefully_on_imap_auth_failure(): void
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

        $response = $this->actingAs($user, 'sanctum')->patchJson("/api/folders/{$folder->id}", [
            'name' => 'Work',
        ]);

        $response->assertStatus(422);
        $this->assertSame('needs_reauth', $account->fresh()->status);
        $this->assertDatabaseHas('folders', ['id' => $folder->id, 'name' => 'Projects']);
    }

    public function test_a_user_cannot_rename_another_users_folder(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $account = EmailAccount::factory()->for($other)->create();
        $folder = Folder::factory()->for($account)->create(['name' => 'Projects', 'type' => 'custom']);

        $response = $this->actingAs($user, 'sanctum')->patchJson("/api/folders/{$folder->id}", [
            'name' => 'Work',
        ]);

        $response->assertForbidden();
        $this->assertDatabaseHas('folders', ['id' => $folder->id, 'name' => 'Projects']);
    }
}
