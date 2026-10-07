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

class BulkMessageActionsTest extends TestCase
{
    use InteractsWithImapMocks;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(MailServerSeeder::class);
    }

    /**
     * Wires a single mocked IMAP connection whose folder(s) answer
     * `whereUidIn()` with the given imap messages, keyed by folder name.
     * Returns the client mock plus each folder mock (keyed the same way),
     * so a test that also needs to move/delete out of one of them can wire
     * `mockBatchMove()` against the exact same folder mock.
     *
     * @param  array<string, list<MockInterface>>  $imapMessagesByFolderName
     * @return array{client: MockInterface, folders: array<string, MockInterface>}
     */
    private function mockBulkConnection(array $imapMessagesByFolderName): array
    {
        $client = Mockery::mock(Client::class);
        $client->shouldReceive('connect')->andReturnSelf();
        $client->shouldReceive('disconnect')->andReturnSelf();
        $client->shouldReceive('expunge')->andReturn([])->byDefault();

        $folders = [];

        foreach ($imapMessagesByFolderName as $folderName => $imapMessages) {
            $whereQuery = Mockery::mock(WhereQuery::class);
            $whereQuery->shouldReceive('whereUidIn')->andReturnSelf();
            $whereQuery->shouldReceive('leaveUnread')->andReturnSelf();
            $whereQuery->shouldReceive('get')->andReturn(MessageCollection::make($imapMessages));

            $imapFolder = Mockery::mock(ImapFolder::class);
            $imapFolder->shouldReceive('query')->andReturn($whereQuery);

            $client->shouldReceive('getFolderByPath')->with($folderName)->andReturn($imapFolder);
            $folders[$folderName] = $imapFolder;
        }

        $this->mock(ClientManager::class, function (MockInterface $mock) use ($client) {
            $mock->shouldReceive('make')->andReturn($client);
        });

        return ['client' => $client, 'folders' => $folders];
    }

    public function test_bulk_marking_messages_as_read_updates_all_of_them_over_one_connection(): void
    {
        $user = User::factory()->create();
        $account = EmailAccount::factory()->for($user)->create();
        $inbox = Folder::factory()->for($account)->create(['name' => 'INBOX', 'type' => 'inbox']);
        $first = Message::factory()->for($inbox)->create(['uid' => 1, 'is_read' => false]);
        $second = Message::factory()->for($inbox)->create(['uid' => 2, 'is_read' => false]);

        $imapFirst = $this->mockImapMessage(['uid' => 1]);
        $imapFirst->shouldReceive('setFlag')->with('Seen')->once();
        $imapSecond = $this->mockImapMessage(['uid' => 2]);
        $imapSecond->shouldReceive('setFlag')->with('Seen')->once();

        $this->mockBulkConnection(['INBOX' => [$imapFirst, $imapSecond]]);

        $response = $this->actingAs($user, 'sanctum')->patchJson('/api/messages/bulk/flags', [
            'message_ids' => [$first->id, $second->id],
            'is_read' => true,
        ]);

        $response->assertOk();
        $this->assertCount(2, $response->json('updated'));
        $this->assertSame([], $response->json('failed'));
        $this->assertDatabaseHas('messages', ['id' => $first->id, 'is_read' => true]);
        $this->assertDatabaseHas('messages', ['id' => $second->id, 'is_read' => true]);
    }

    public function test_bulk_starring_messages_works(): void
    {
        $user = User::factory()->create();
        $account = EmailAccount::factory()->for($user)->create();
        $inbox = Folder::factory()->for($account)->create(['name' => 'INBOX', 'type' => 'inbox']);
        $message = Message::factory()->for($inbox)->create(['uid' => 1, 'is_flagged' => false]);

        $imapMessage = $this->mockImapMessage(['uid' => 1]);
        $imapMessage->shouldReceive('setFlag')->with('Flagged')->once();
        $this->mockBulkConnection(['INBOX' => [$imapMessage]]);

        $response = $this->actingAs($user, 'sanctum')->patchJson('/api/messages/bulk/flags', [
            'message_ids' => [$message->id],
            'is_flagged' => true,
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('messages', ['id' => $message->id, 'is_flagged' => true]);
    }

    public function test_bulk_flags_requires_at_least_one_flag_field(): void
    {
        $user = User::factory()->create();
        $account = EmailAccount::factory()->for($user)->create();
        $inbox = Folder::factory()->for($account)->create(['name' => 'INBOX', 'type' => 'inbox']);
        $message = Message::factory()->for($inbox)->create();

        $response = $this->actingAs($user, 'sanctum')->patchJson('/api/messages/bulk/flags', [
            'message_ids' => [$message->id],
        ]);

        $response->assertStatus(422);
    }

    public function test_bulk_flags_rejects_message_ids_the_user_does_not_own(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $otherAccount = EmailAccount::factory()->for($other)->create();
        $otherFolder = Folder::factory()->for($otherAccount)->create();
        $otherMessage = Message::factory()->for($otherFolder)->create();

        $response = $this->actingAs($user, 'sanctum')->patchJson('/api/messages/bulk/flags', [
            'message_ids' => [$otherMessage->id],
            'is_read' => true,
        ]);

        $response->assertForbidden();
    }

    public function test_bulk_flags_requires_a_non_empty_message_id_list(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user, 'sanctum')->patchJson('/api/messages/bulk/flags', [
            'message_ids' => [],
            'is_read' => true,
        ]);

        $response->assertStatus(422);
    }

    public function test_bulk_move_moves_every_eligible_message_to_the_target_folder(): void
    {
        $user = User::factory()->create();
        $account = EmailAccount::factory()->for($user)->create();
        $inbox = Folder::factory()->for($account)->create(['name' => 'INBOX', 'type' => 'inbox']);
        $archive = Folder::factory()->for($account)->create(['name' => 'Archive', 'type' => 'archive']);
        $first = Message::factory()->for($inbox)->create(['uid' => 1]);
        $second = Message::factory()->for($inbox)->create(['uid' => 2]);

        $imapFirst = $this->mockImapMessage(['uid' => 1]);
        $imapSecond = $this->mockImapMessage(['uid' => 2]);

        ['client' => $client, 'folders' => $folders] = $this->mockBulkConnection(['INBOX' => [$imapFirst, $imapSecond]]);
        // Both messages land in one source-folder group — moved with a
        // single batched moveManyMessages() call instead of one move() per
        // message, the whole point of the optimization.
        $this->mockBatchMove($client, $folders['INBOX'], 'Archive', [1, 2], 101);

        $response = $this->actingAs($user, 'sanctum')->patchJson('/api/messages/bulk/move', [
            'message_ids' => [$first->id, $second->id],
            'folder_id' => $archive->id,
        ]);

        $response->assertOk();
        $this->assertCount(2, $response->json('moved'));
        $this->assertSame([], $response->json('skipped'));
        $this->assertDatabaseHas('messages', ['id' => $first->id, 'folder_id' => $archive->id, 'uid' => 101]);
        $this->assertDatabaseHas('messages', ['id' => $second->id, 'folder_id' => $archive->id, 'uid' => 102]);
    }

    /**
     * A bulk selection can legitimately mix eligible and ineligible messages
     * (e.g. selected from a cross-folder view) — the eligible one still
     * moves, the ineligible one is skipped with a reason instead of failing
     * the whole batch (CLAUDE.md §8 items 66/68).
     */
    public function test_bulk_move_skips_ineligible_messages_but_still_moves_the_rest(): void
    {
        $user = User::factory()->create();
        $account = EmailAccount::factory()->for($user)->create();
        $inbox = Folder::factory()->for($account)->create(['name' => 'INBOX', 'type' => 'inbox']);
        $sent = Folder::factory()->for($account)->create(['name' => 'Sent', 'type' => 'sent']);
        $movable = Message::factory()->for($inbox)->create(['uid' => 1]);
        $unmovable = Message::factory()->for($sent)->create(['uid' => 2]);

        $imapMessage = $this->mockImapMessage(['uid' => 1]);
        ['client' => $client, 'folders' => $folders] = $this->mockBulkConnection(['INBOX' => [$imapMessage]]);
        $this->mockBatchMove($client, $folders['INBOX'], 'Archive', [1], 101);

        $archive = Folder::factory()->for($account)->create(['name' => 'Archive', 'type' => 'archive']);

        $response = $this->actingAs($user, 'sanctum')->patchJson('/api/messages/bulk/move', [
            'message_ids' => [$movable->id, $unmovable->id],
            'folder_id' => $archive->id,
        ]);

        $response->assertOk();
        $this->assertCount(1, $response->json('moved'));
        $this->assertCount(1, $response->json('skipped'));
        $this->assertSame($unmovable->id, $response->json('skipped.0.id'));
        $this->assertDatabaseHas('messages', ['id' => $movable->id, 'folder_id' => $archive->id]);
        $this->assertDatabaseHas('messages', ['id' => $unmovable->id, 'folder_id' => $sent->id]);
    }

    public function test_bulk_delete_moves_non_trash_messages_to_trash(): void
    {
        $user = User::factory()->create();
        $account = EmailAccount::factory()->for($user)->create();
        $inbox = Folder::factory()->for($account)->create(['name' => 'INBOX', 'type' => 'inbox']);
        $trash = Folder::factory()->for($account)->create(['name' => 'Trash', 'type' => 'trash']);
        $message = Message::factory()->for($inbox)->create(['uid' => 1]);

        $imapMessage = $this->mockImapMessage(['uid' => 1]);
        ['client' => $client, 'folders' => $folders] = $this->mockBulkConnection(['INBOX' => [$imapMessage]]);
        $this->mockBatchMove($client, $folders['INBOX'], 'Trash', [1], 201);

        $response = $this->actingAs($user, 'sanctum')->deleteJson('/api/messages/bulk', [
            'message_ids' => [$message->id],
        ]);

        $response->assertOk();
        $this->assertCount(1, $response->json('trashed'));
        $this->assertSame(0, $response->json('deleted_count'));
        $this->assertDatabaseHas('messages', ['id' => $message->id, 'folder_id' => $trash->id, 'uid' => 201]);
    }

    /**
     * Messages already in Trash are permanently deleted with a single
     * `expunge()` call for the whole batch, mirroring `emptyTrash()`'s
     * optimization rather than one EXPUNGE per message.
     */
    public function test_bulk_delete_permanently_removes_messages_already_in_trash_with_one_expunge(): void
    {
        $user = User::factory()->create();
        $account = EmailAccount::factory()->for($user)->create();
        $trash = Folder::factory()->for($account)->create(['name' => 'Trash', 'type' => 'trash']);
        $first = Message::factory()->for($trash)->create(['uid' => 1]);
        $second = Message::factory()->for($trash)->create(['uid' => 2]);

        $imapFirst = $this->mockImapMessage(['uid' => 1]);
        $imapFirst->shouldReceive('setFlag')->with('Deleted')->once();
        $imapSecond = $this->mockImapMessage(['uid' => 2]);
        $imapSecond->shouldReceive('setFlag')->with('Deleted')->once();

        ['client' => $client] = $this->mockBulkConnection(['Trash' => [$imapFirst, $imapSecond]]);
        $client->shouldReceive('expunge')->once()->andReturn([]);

        $response = $this->actingAs($user, 'sanctum')->deleteJson('/api/messages/bulk', [
            'message_ids' => [$first->id, $second->id],
        ]);

        $response->assertOk();
        $this->assertSame(2, $response->json('deleted_count'));
        $this->assertSame([], $response->json('trashed'));
        $this->assertDatabaseMissing('messages', ['id' => $first->id]);
        $this->assertDatabaseMissing('messages', ['id' => $second->id]);
    }

    public function test_bulk_delete_rejects_message_ids_the_user_does_not_own(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $otherAccount = EmailAccount::factory()->for($other)->create();
        $otherFolder = Folder::factory()->for($otherAccount)->create();
        $otherMessage = Message::factory()->for($otherFolder)->create();

        $response = $this->actingAs($user, 'sanctum')->deleteJson('/api/messages/bulk', [
            'message_ids' => [$otherMessage->id],
        ]);

        $response->assertForbidden();
    }

    public function test_bulk_move_rejects_a_missing_target_folder(): void
    {
        $user = User::factory()->create();
        $account = EmailAccount::factory()->for($user)->create();
        $inbox = Folder::factory()->for($account)->create(['name' => 'INBOX', 'type' => 'inbox']);
        $message = Message::factory()->for($inbox)->create();

        $response = $this->actingAs($user, 'sanctum')->patchJson('/api/messages/bulk/move', [
            'message_ids' => [$message->id],
            'folder_id' => 999999,
        ]);

        $response->assertStatus(422);
    }
}
