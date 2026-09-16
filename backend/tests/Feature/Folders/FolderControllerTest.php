<?php

declare(strict_types=1);

namespace Tests\Feature\Folders;

use App\Models\EmailAccount;
use App\Models\User;
use Database\Seeders\MailServerSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithImapMocks;
use Tests\TestCase;
use Webklex\PHPIMAP\Client;
use Webklex\PHPIMAP\ClientManager;
use Webklex\PHPIMAP\Exceptions\AuthFailedException;

class FolderControllerTest extends TestCase
{
    use InteractsWithImapMocks;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(MailServerSeeder::class);
    }

    public function test_syncing_folders_maps_special_use_flags_and_falls_back_to_name(): void
    {
        $this->mockRawFolderList([
            'INBOX' => ['delimiter' => '/', 'flags' => ['\HasNoChildren']],
            'Sent' => ['delimiter' => '/', 'flags' => ['\HasNoChildren', '\Sent']],
            'Some Random Folder' => ['delimiter' => '/', 'flags' => ['\HasNoChildren']],
        ]);

        $user = User::factory()->create();
        $account = EmailAccount::factory()->for($user)->create();

        $response = $this->actingAs($user, 'sanctum')->getJson("/api/email-accounts/{$account->id}/folders");

        $response->assertOk();
        $names = collect($response->json())->keyBy('name');

        $this->assertSame('inbox', $names['INBOX']['type']);
        $this->assertSame('sent', $names['Sent']['type']);
        $this->assertSame('custom', $names['Some Random Folder']['type']);
        $this->assertSame(0, $names['INBOX']['unread_count']);

        $this->assertDatabaseHas('folders', [
            'email_account_id' => $account->id,
            'name' => 'INBOX',
            'type' => 'inbox',
        ]);
    }

    public function test_syncing_folders_twice_upserts_rather_than_duplicating(): void
    {
        $this->mockRawFolderList([
            'INBOX' => ['delimiter' => '/', 'flags' => []],
        ]);

        $user = User::factory()->create();
        $account = EmailAccount::factory()->for($user)->create();

        $this->actingAs($user, 'sanctum')->getJson("/api/email-accounts/{$account->id}/folders");
        $this->actingAs($user, 'sanctum')->getJson("/api/email-accounts/{$account->id}/folders");

        $this->assertDatabaseCount('folders', 1);
    }

    public function test_a_user_cannot_sync_another_users_account_folders(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $account = EmailAccount::factory()->for($other)->create();

        $response = $this->actingAs($user, 'sanctum')->getJson("/api/email-accounts/{$account->id}/folders");

        $response->assertForbidden();
    }

    public function test_folder_sync_marks_needs_reauth_on_auth_failure(): void
    {
        $client = \Mockery::mock(Client::class);
        $client->shouldReceive('connect')->andThrow(new AuthFailedException);
        $client->shouldReceive('disconnect')->andReturnSelf();

        $this->mock(ClientManager::class, function ($mock) use ($client) {
            $mock->shouldReceive('make')->andReturn($client);
        });

        $user = User::factory()->create();
        $account = EmailAccount::factory()->for($user)->create(['status' => 'active']);

        $response = $this->actingAs($user, 'sanctum')->getJson("/api/email-accounts/{$account->id}/folders");

        $response->assertStatus(422);
        $this->assertSame('needs_reauth', $account->fresh()->status);
    }
}
