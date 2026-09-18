<?php

declare(strict_types=1);

namespace Tests\Feature\Drafts;

use App\Models\EmailAccount;
use App\Models\Folder;
use App\Models\Message;
use App\Models\User;
use Database\Seeders\MailServerSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Mockery\MockInterface;
use Tests\Concerns\InteractsWithImapMocks;
use Tests\TestCase;
use Webklex\PHPIMAP\Client;
use Webklex\PHPIMAP\ClientManager;
use Webklex\PHPIMAP\Exceptions\AuthFailedException;
use Webklex\PHPIMAP\Query\WhereQuery;
use Webklex\PHPIMAP\Support\MessageCollection;

class DraftControllerTest extends TestCase
{
    use InteractsWithImapMocks;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(MailServerSeeder::class);
    }

    public function test_saving_a_new_draft_appends_it_to_the_real_drafts_folder(): void
    {
        $this->mockSuccessfulImapAppend(uid: 42);

        $user = User::factory()->create();
        $account = EmailAccount::factory()->for($user)->create();
        $drafts = Folder::factory()->for($account)->create(['name' => 'Drafts', 'type' => 'drafts']);

        $response = $this->actingAs($user, 'sanctum')->postJson("/api/email-accounts/{$account->id}/drafts", [
            'subject' => 'Draft subject',
            'body_html' => '<p>Draft body</p>',
        ]);

        $response->assertCreated();
        $response->assertJsonPath('subject', 'Draft subject');

        $this->assertDatabaseHas('messages', [
            'folder_id' => $drafts->id,
            'subject' => 'Draft subject',
            'uid' => 42,
        ]);
    }

    public function test_saving_a_new_draft_with_an_attachment_stores_it(): void
    {
        Storage::fake('local');
        $this->mockSuccessfulImapAppend();

        $user = User::factory()->create();
        $account = EmailAccount::factory()->for($user)->create();
        Folder::factory()->for($account)->create(['name' => 'Drafts', 'type' => 'drafts']);

        $file = UploadedFile::fake()->create('draft.pdf', 20, 'application/pdf');

        $response = $this->actingAs($user, 'sanctum')->post("/api/email-accounts/{$account->id}/drafts", [
            'subject' => 'Draft with file',
            'attachments' => [$file],
        ]);

        $response->assertCreated();
        $response->assertJsonPath('attachments.0.filename', 'draft.pdf');
        $this->assertDatabaseHas('message_attachments', ['filename' => 'draft.pdf']);
    }

    public function test_saving_a_draft_without_any_field_filled_in_succeeds(): void
    {
        $this->mockSuccessfulImapAppend();

        $user = User::factory()->create();
        $account = EmailAccount::factory()->for($user)->create();
        Folder::factory()->for($account)->create(['name' => 'Drafts', 'type' => 'drafts']);

        $response = $this->actingAs($user, 'sanctum')->postJson("/api/email-accounts/{$account->id}/drafts", []);

        $response->assertCreated();
    }

    public function test_saving_a_draft_with_no_synced_drafts_folder_fails(): void
    {
        $user = User::factory()->create();
        $account = EmailAccount::factory()->for($user)->create();

        $response = $this->actingAs($user, 'sanctum')->postJson("/api/email-accounts/{$account->id}/drafts", [
            'subject' => 'No drafts folder',
        ]);

        $response->assertStatus(503);
        $this->assertDatabaseMissing('messages', ['subject' => 'No drafts folder']);
    }

    public function test_saving_a_draft_fails_gracefully_on_imap_auth_failure(): void
    {
        $client = Mockery::mock(Client::class);
        $client->shouldReceive('connect')->andThrow(new AuthFailedException);
        $client->shouldReceive('disconnect')->andReturnSelf();
        $this->mock(ClientManager::class, function (MockInterface $mock) use ($client) {
            $mock->shouldReceive('make')->andReturn($client);
        });

        $user = User::factory()->create();
        $account = EmailAccount::factory()->for($user)->create(['status' => 'active']);
        Folder::factory()->for($account)->create(['name' => 'Drafts', 'type' => 'drafts']);

        $response = $this->actingAs($user, 'sanctum')->postJson("/api/email-accounts/{$account->id}/drafts", [
            'subject' => 'Will fail',
        ]);

        $response->assertStatus(422);
        $this->assertSame('needs_reauth', $account->fresh()->status);
        $this->assertDatabaseMissing('messages', ['subject' => 'Will fail']);
    }

    public function test_updating_a_draft_deletes_the_old_copy_and_appends_the_new_one(): void
    {
        $existingImapMessage = $this->mockImapMessage(['uid' => 10]);
        $existingImapMessage->shouldReceive('delete')->once()->andReturnTrue();

        $whereQuery = Mockery::mock(WhereQuery::class);
        $whereQuery->shouldReceive('whereUid')->andReturnSelf();
        $whereQuery->shouldReceive('leaveUnread')->andReturnSelf();
        $whereQuery->shouldReceive('get')->andReturn(MessageCollection::make([$existingImapMessage]));

        $imapFolder = Mockery::mock(\Webklex\PHPIMAP\Folder::class);
        $imapFolder->shouldReceive('query')->andReturn($whereQuery);
        $imapFolder->shouldReceive('examine')->andReturn(['uidnext' => 11]);
        $imapFolder->shouldReceive('appendMessage')->once()->andReturn([]);

        $client = Mockery::mock(Client::class);
        $client->shouldReceive('connect')->andReturnSelf();
        $client->shouldReceive('disconnect')->andReturnSelf();
        $client->shouldReceive('getFolderByPath')->andReturn($imapFolder);
        $this->mock(ClientManager::class, function (MockInterface $mock) use ($client) {
            $mock->shouldReceive('make')->andReturn($client);
        });

        $user = User::factory()->create();
        $account = EmailAccount::factory()->for($user)->create();
        $drafts = Folder::factory()->for($account)->create(['name' => 'Drafts', 'type' => 'drafts']);
        $draft = Message::factory()->for($drafts)->create(['uid' => 10, 'subject' => 'Old subject']);

        $response = $this->actingAs($user, 'sanctum')->putJson("/api/drafts/{$draft->id}", [
            'subject' => 'Updated subject',
            'body_html' => '<p>Updated</p>',
        ]);

        $response->assertOk();
        $response->assertJsonPath('subject', 'Updated subject');

        $this->assertDatabaseHas('messages', [
            'id' => $draft->id,
            'subject' => 'Updated subject',
            'uid' => 11,
        ]);
    }

    public function test_updating_a_draft_via_method_spoofed_post_with_an_attachment_stores_it(): void
    {
        Storage::fake('local');

        $existingImapMessage = $this->mockImapMessage(['uid' => 10]);
        $existingImapMessage->shouldReceive('delete')->once()->andReturnTrue();

        $whereQuery = Mockery::mock(WhereQuery::class);
        $whereQuery->shouldReceive('whereUid')->andReturnSelf();
        $whereQuery->shouldReceive('leaveUnread')->andReturnSelf();
        $whereQuery->shouldReceive('get')->andReturn(MessageCollection::make([$existingImapMessage]));

        $imapFolder = Mockery::mock(\Webklex\PHPIMAP\Folder::class);
        $imapFolder->shouldReceive('query')->andReturn($whereQuery);
        $imapFolder->shouldReceive('examine')->andReturn(['uidnext' => 55]);
        $imapFolder->shouldReceive('appendMessage')->once()->andReturn([]);

        $client = Mockery::mock(Client::class);
        $client->shouldReceive('connect')->andReturnSelf();
        $client->shouldReceive('disconnect')->andReturnSelf();
        $client->shouldReceive('getFolderByPath')->andReturn($imapFolder);
        $this->mock(ClientManager::class, function (MockInterface $mock) use ($client) {
            $mock->shouldReceive('make')->andReturn($client);
        });

        $user = User::factory()->create();
        $account = EmailAccount::factory()->for($user)->create();
        $drafts = Folder::factory()->for($account)->create(['name' => 'Drafts', 'type' => 'drafts']);
        $draft = Message::factory()->for($drafts)->create(['uid' => 10, 'subject' => 'Old subject']);

        $file = UploadedFile::fake()->create('draft2.pdf', 20, 'application/pdf');

        $response = $this->actingAs($user, 'sanctum')->post("/api/drafts/{$draft->id}", [
            '_method' => 'PUT',
            'subject' => 'Updated with file',
            'attachments' => [$file],
        ]);

        $response->assertOk();
        $response->assertJsonPath('attachments.0.filename', 'draft2.pdf');
        $this->assertDatabaseHas('message_attachments', ['message_id' => $draft->id, 'filename' => 'draft2.pdf']);
    }

    public function test_updating_a_non_draft_message_is_rejected(): void
    {
        $user = User::factory()->create();
        $account = EmailAccount::factory()->for($user)->create();
        $inbox = Folder::factory()->for($account)->create(['name' => 'INBOX', 'type' => 'inbox']);
        $message = Message::factory()->for($inbox)->create();

        $response = $this->actingAs($user, 'sanctum')->putJson("/api/drafts/{$message->id}", [
            'subject' => 'Nope',
        ]);

        $response->assertStatus(422);
    }

    public function test_a_user_cannot_save_a_draft_for_another_users_account(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $account = EmailAccount::factory()->for($other)->create();

        $response = $this->actingAs($user, 'sanctum')->postJson("/api/email-accounts/{$account->id}/drafts", [
            'subject' => 'Nope',
        ]);

        $response->assertForbidden();
    }

    public function test_a_user_cannot_update_another_users_draft(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $account = EmailAccount::factory()->for($other)->create();
        $drafts = Folder::factory()->for($account)->create(['name' => 'Drafts', 'type' => 'drafts']);
        $draft = Message::factory()->for($drafts)->create();

        $response = $this->actingAs($user, 'sanctum')->putJson("/api/drafts/{$draft->id}", [
            'subject' => 'Nope',
        ]);

        $response->assertForbidden();
    }
}
