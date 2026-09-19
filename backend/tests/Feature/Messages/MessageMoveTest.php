<?php

declare(strict_types=1);

namespace Tests\Feature\Messages;

use App\Models\EmailAccount;
use App\Models\Folder;
use App\Models\Message;
use App\Models\User;
use Database\Seeders\MailServerSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Mockery\MockInterface;
use Tests\Concerns\InteractsWithImapMocks;
use Tests\TestCase;
use Webklex\PHPIMAP\Client;
use Webklex\PHPIMAP\ClientManager;
use Webklex\PHPIMAP\Folder as ImapFolder;
use Webklex\PHPIMAP\Query\WhereQuery;
use Webklex\PHPIMAP\Support\MessageCollection;

class MessageMoveTest extends TestCase
{
    use InteractsWithImapMocks;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(MailServerSeeder::class);
    }

    public function test_moving_a_message_updates_its_folder_and_uid_locally(): void
    {
        $user = User::factory()->create();
        $account = EmailAccount::factory()->for($user)->create();
        $inbox = Folder::factory()->for($account)->create(['name' => 'INBOX', 'type' => 'inbox']);
        $archive = Folder::factory()->for($account)->create(['name' => 'Archive', 'type' => 'archive']);
        $message = Message::factory()->for($inbox)->create(['uid' => 5]);

        $imapMessage = $this->mockImapMessage(['uid' => 5]);

        $whereQuery = Mockery::mock(WhereQuery::class);
        $whereQuery->shouldReceive('whereUid')->andReturnSelf();
        $whereQuery->shouldReceive('leaveUnread')->andReturnSelf();
        $whereQuery->shouldReceive('get')->andReturn(MessageCollection::make([$imapMessage]));

        $imapFolder = Mockery::mock(ImapFolder::class);
        $imapFolder->shouldReceive('query')->andReturn($whereQuery);

        $client = Mockery::mock(Client::class);
        $client->shouldReceive('connect')->andReturnSelf();
        $client->shouldReceive('disconnect')->andReturnSelf();
        $client->shouldReceive('getFolderByPath')->with('INBOX')->andReturn($imapFolder);

        $this->mockBatchMove($client, $imapFolder, 'Archive', [5], 99);

        $this->mock(ClientManager::class, function (MockInterface $mock) use ($client) {
            $mock->shouldReceive('make')->andReturn($client);
        });

        $response = $this->actingAs($user, 'sanctum')->patchJson("/api/messages/{$message->id}/move", [
            'folder_id' => $archive->id,
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('messages', [
            'id' => $message->id,
            'folder_id' => $archive->id,
            'uid' => 99,
        ]);
    }

    public function test_moving_a_message_to_its_current_folder_is_rejected(): void
    {
        $user = User::factory()->create();
        $account = EmailAccount::factory()->for($user)->create();
        $inbox = Folder::factory()->for($account)->create(['name' => 'INBOX', 'type' => 'inbox']);
        $message = Message::factory()->for($inbox)->create();

        $response = $this->actingAs($user, 'sanctum')->patchJson("/api/messages/{$message->id}/move", [
            'folder_id' => $inbox->id,
        ]);

        $response->assertStatus(422);
    }

    public function test_moving_a_message_to_a_folder_in_another_account_is_rejected(): void
    {
        $user = User::factory()->create();
        $account = EmailAccount::factory()->for($user)->create();
        $otherAccount = EmailAccount::factory()->for($user)->create();
        $inbox = Folder::factory()->for($account)->create(['name' => 'INBOX']);
        $otherFolder = Folder::factory()->for($otherAccount)->create(['name' => 'INBOX']);
        $message = Message::factory()->for($inbox)->create();

        $response = $this->actingAs($user, 'sanctum')->patchJson("/api/messages/{$message->id}/move", [
            'folder_id' => $otherFolder->id,
        ]);

        $response->assertStatus(422);
    }

    public function test_a_user_cannot_move_another_users_message(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $account = EmailAccount::factory()->for($other)->create();
        $folder = Folder::factory()->for($account)->create(['name' => 'INBOX']);
        $target = Folder::factory()->for($account)->create(['name' => 'Archive']);
        $message = Message::factory()->for($folder)->create();

        $response = $this->actingAs($user, 'sanctum')->patchJson("/api/messages/{$message->id}/move", [
            'folder_id' => $target->id,
        ]);

        $response->assertForbidden();
    }

    /**
     * §8 item 66 — Sent/Drafts only ever hold what the system itself put
     * there (an actually-sent message, a saved draft); moving an arbitrary
     * message into either would misrepresent it.
     */
    public function test_moving_a_message_into_the_sent_folder_is_rejected(): void
    {
        $user = User::factory()->create();
        $account = EmailAccount::factory()->for($user)->create();
        $inbox = Folder::factory()->for($account)->create(['name' => 'INBOX', 'type' => 'inbox']);
        $sent = Folder::factory()->for($account)->create(['name' => 'Sent', 'type' => 'sent']);
        $message = Message::factory()->for($inbox)->create();

        $response = $this->actingAs($user, 'sanctum')->patchJson("/api/messages/{$message->id}/move", [
            'folder_id' => $sent->id,
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseHas('messages', ['id' => $message->id, 'folder_id' => $inbox->id]);
    }

    public function test_moving_a_message_into_the_drafts_folder_is_rejected(): void
    {
        $user = User::factory()->create();
        $account = EmailAccount::factory()->for($user)->create();
        $inbox = Folder::factory()->for($account)->create(['name' => 'INBOX', 'type' => 'inbox']);
        $drafts = Folder::factory()->for($account)->create(['name' => 'Drafts', 'type' => 'drafts']);
        $message = Message::factory()->for($inbox)->create();

        $response = $this->actingAs($user, 'sanctum')->patchJson("/api/messages/{$message->id}/move", [
            'folder_id' => $drafts->id,
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseHas('messages', ['id' => $message->id, 'folder_id' => $inbox->id]);
    }

    public function test_moving_a_draft_out_of_the_drafts_folder_is_rejected(): void
    {
        $user = User::factory()->create();
        $account = EmailAccount::factory()->for($user)->create();
        $drafts = Folder::factory()->for($account)->create(['name' => 'Drafts', 'type' => 'drafts']);
        $archive = Folder::factory()->for($account)->create(['name' => 'Archive', 'type' => 'archive']);
        $draft = Message::factory()->for($drafts)->create();

        $response = $this->actingAs($user, 'sanctum')->patchJson("/api/messages/{$draft->id}/move", [
            'folder_id' => $archive->id,
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseHas('messages', ['id' => $draft->id, 'folder_id' => $drafts->id]);
    }

    /**
     * §8 item 68 — a Sent message was never received, so moving it into an
     * "inbound" folder (Inbox/Spam) would misrepresent its origin. Reported
     * directly by the user against a real account: moving Sent → Inbox
     * "made no sense."
     */
    public function test_moving_a_sent_message_into_the_inbox_is_rejected(): void
    {
        $user = User::factory()->create();
        $account = EmailAccount::factory()->for($user)->create();
        $sent = Folder::factory()->for($account)->create(['name' => 'Sent', 'type' => 'sent']);
        $inbox = Folder::factory()->for($account)->create(['name' => 'INBOX', 'type' => 'inbox']);
        $message = Message::factory()->for($sent)->create();

        $response = $this->actingAs($user, 'sanctum')->patchJson("/api/messages/{$message->id}/move", [
            'folder_id' => $inbox->id,
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseHas('messages', ['id' => $message->id, 'folder_id' => $sent->id]);
    }

    public function test_moving_a_sent_message_into_spam_is_rejected(): void
    {
        $user = User::factory()->create();
        $account = EmailAccount::factory()->for($user)->create();
        $sent = Folder::factory()->for($account)->create(['name' => 'Sent', 'type' => 'sent']);
        $spam = Folder::factory()->for($account)->create(['name' => 'Junk', 'type' => 'spam']);
        $message = Message::factory()->for($sent)->create();

        $response = $this->actingAs($user, 'sanctum')->patchJson("/api/messages/{$message->id}/move", [
            'folder_id' => $spam->id,
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseHas('messages', ['id' => $message->id, 'folder_id' => $sent->id]);
    }

    /**
     * A Sent message can still be archived/trashed/organized — only the
     * inbound-folder crossing is blocked (§8 item 68), not every move.
     */
    public function test_moving_a_sent_message_into_archive_is_allowed(): void
    {
        $user = User::factory()->create();
        $account = EmailAccount::factory()->for($user)->create();
        $sent = Folder::factory()->for($account)->create(['name' => 'Sent', 'type' => 'sent']);
        $archive = Folder::factory()->for($account)->create(['name' => 'Archive', 'type' => 'archive']);
        $message = Message::factory()->for($sent)->create(['uid' => 5]);

        $imapMessage = $this->mockImapMessage(['uid' => 5]);

        $whereQuery = Mockery::mock(WhereQuery::class);
        $whereQuery->shouldReceive('whereUid')->andReturnSelf();
        $whereQuery->shouldReceive('leaveUnread')->andReturnSelf();
        $whereQuery->shouldReceive('get')->andReturn(MessageCollection::make([$imapMessage]));

        $imapFolder = Mockery::mock(ImapFolder::class);
        $imapFolder->shouldReceive('query')->andReturn($whereQuery);

        $client = Mockery::mock(Client::class);
        $client->shouldReceive('connect')->andReturnSelf();
        $client->shouldReceive('disconnect')->andReturnSelf();
        $client->shouldReceive('getFolderByPath')->with('Sent')->andReturn($imapFolder);

        $this->mockBatchMove($client, $imapFolder, 'Archive', [5], 99);

        $this->mock(ClientManager::class, function (MockInterface $mock) use ($client) {
            $mock->shouldReceive('make')->andReturn($client);
        });

        $response = $this->actingAs($user, 'sanctum')->patchJson("/api/messages/{$message->id}/move", [
            'folder_id' => $archive->id,
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('messages', ['id' => $message->id, 'folder_id' => $archive->id]);
    }

    /**
     * Restoring a message from Trash back to the Inbox stays allowed — Trash
     * is a neutral staging folder, not an "outbound" one, so it never
     * triggers the inbound/outbound restriction (§8 item 68).
     */
    public function test_moving_a_message_from_trash_into_the_inbox_is_allowed(): void
    {
        $user = User::factory()->create();
        $account = EmailAccount::factory()->for($user)->create();
        $trash = Folder::factory()->for($account)->create(['name' => 'Trash', 'type' => 'trash']);
        $inbox = Folder::factory()->for($account)->create(['name' => 'INBOX', 'type' => 'inbox']);
        $message = Message::factory()->for($trash)->create(['uid' => 5]);

        $imapMessage = $this->mockImapMessage(['uid' => 5]);

        $whereQuery = Mockery::mock(WhereQuery::class);
        $whereQuery->shouldReceive('whereUid')->andReturnSelf();
        $whereQuery->shouldReceive('leaveUnread')->andReturnSelf();
        $whereQuery->shouldReceive('get')->andReturn(MessageCollection::make([$imapMessage]));

        $imapFolder = Mockery::mock(ImapFolder::class);
        $imapFolder->shouldReceive('query')->andReturn($whereQuery);

        $client = Mockery::mock(Client::class);
        $client->shouldReceive('connect')->andReturnSelf();
        $client->shouldReceive('disconnect')->andReturnSelf();
        $client->shouldReceive('getFolderByPath')->with('Trash')->andReturn($imapFolder);

        $this->mockBatchMove($client, $imapFolder, 'INBOX', [5], 99);

        $this->mock(ClientManager::class, function (MockInterface $mock) use ($client) {
            $mock->shouldReceive('make')->andReturn($client);
        });

        $response = $this->actingAs($user, 'sanctum')->patchJson("/api/messages/{$message->id}/move", [
            'folder_id' => $inbox->id,
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('messages', ['id' => $message->id, 'folder_id' => $inbox->id]);
    }
}
