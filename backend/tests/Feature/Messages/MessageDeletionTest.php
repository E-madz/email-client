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

class MessageDeletionTest extends TestCase
{
    use InteractsWithImapMocks;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(MailServerSeeder::class);
    }

    public function test_deleting_a_message_moves_it_to_trash(): void
    {
        $user = User::factory()->create();
        $account = EmailAccount::factory()->for($user)->create();
        $inbox = Folder::factory()->for($account)->create(['name' => 'INBOX', 'type' => 'inbox']);
        $trash = Folder::factory()->for($account)->create(['name' => 'Trash', 'type' => 'trash']);
        $message = Message::factory()->for($inbox)->create(['uid' => 5]);

        $movedImapMessage = $this->mockImapMessage(['uid' => 42]);
        $imapMessage = $this->mockImapMessage(['uid' => 5]);
        $imapMessage->shouldReceive('move')->with('Trash', expunge: true)->once()->andReturn($movedImapMessage);

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

        $this->mock(ClientManager::class, function (MockInterface $mock) use ($client) {
            $mock->shouldReceive('make')->andReturn($client);
        });

        $response = $this->actingAs($user, 'sanctum')->deleteJson("/api/messages/{$message->id}");

        $response->assertOk();
        $this->assertDatabaseHas('messages', [
            'id' => $message->id,
            'folder_id' => $trash->id,
            'uid' => 42,
        ]);
    }

    public function test_deleting_a_message_already_in_trash_permanently_deletes_it(): void
    {
        $user = User::factory()->create();
        $account = EmailAccount::factory()->for($user)->create();
        $trash = Folder::factory()->for($account)->create(['name' => 'Trash', 'type' => 'trash']);
        $message = Message::factory()->for($trash)->create(['uid' => 7]);

        $imapMessage = $this->mockImapMessage(['uid' => 7]);
        $imapMessage->shouldReceive('delete')->with(expunge: true)->once()->andReturnTrue();

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

        $this->mock(ClientManager::class, function (MockInterface $mock) use ($client) {
            $mock->shouldReceive('make')->andReturn($client);
        });

        $response = $this->actingAs($user, 'sanctum')->deleteJson("/api/messages/{$message->id}");

        $response->assertStatus(204);
        $this->assertDatabaseMissing('messages', ['id' => $message->id]);
    }

    public function test_deleting_without_a_synced_trash_folder_returns_503(): void
    {
        $user = User::factory()->create();
        $account = EmailAccount::factory()->for($user)->create();
        $inbox = Folder::factory()->for($account)->create(['name' => 'INBOX', 'type' => 'inbox']);
        $message = Message::factory()->for($inbox)->create();

        $response = $this->actingAs($user, 'sanctum')->deleteJson("/api/messages/{$message->id}");

        $response->assertStatus(503);
        $this->assertDatabaseHas('messages', ['id' => $message->id, 'folder_id' => $inbox->id]);
    }

    public function test_a_user_cannot_delete_another_users_message(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $account = EmailAccount::factory()->for($other)->create();
        $folder = Folder::factory()->for($account)->create();
        $message = Message::factory()->for($folder)->create();

        $response = $this->actingAs($user, 'sanctum')->deleteJson("/api/messages/{$message->id}");

        $response->assertForbidden();
        $this->assertDatabaseHas('messages', ['id' => $message->id]);
    }

    public function test_emptying_trash_deletes_all_messages_remotely_and_locally(): void
    {
        $user = User::factory()->create();
        $account = EmailAccount::factory()->for($user)->create();
        $trash = Folder::factory()->for($account)->create(['name' => 'Trash', 'type' => 'trash']);
        $first = Message::factory()->for($trash)->create(['uid' => 1]);
        $second = Message::factory()->for($trash)->create(['uid' => 2]);

        $imapFirst = $this->mockImapMessage(['uid' => 1]);
        $imapFirst->shouldReceive('setFlag')->with('Deleted')->once()->andReturnTrue();
        $imapSecond = $this->mockImapMessage(['uid' => 2]);
        $imapSecond->shouldReceive('setFlag')->with('Deleted')->once()->andReturnTrue();

        $whereQuery = Mockery::mock(WhereQuery::class);
        $whereQuery->shouldReceive('whereUid')->with(1)->andReturnSelf();
        $whereQuery->shouldReceive('whereUid')->with(2)->andReturnSelf();
        $whereQuery->shouldReceive('leaveUnread')->andReturnSelf();
        $whereQuery->shouldReceive('get')->andReturn(
            MessageCollection::make([$imapFirst]),
            MessageCollection::make([$imapSecond]),
        );

        $imapFolder = Mockery::mock(ImapFolder::class);
        $imapFolder->shouldReceive('query')->andReturn($whereQuery);

        $client = Mockery::mock(Client::class);
        $client->shouldReceive('connect')->andReturnSelf();
        $client->shouldReceive('disconnect')->andReturnSelf();
        $client->shouldReceive('getFolderByPath')->with('Trash')->andReturn($imapFolder);
        $client->shouldReceive('expunge')->once()->andReturn([]);

        $this->mock(ClientManager::class, function (MockInterface $mock) use ($client) {
            $mock->shouldReceive('make')->andReturn($client);
        });

        $response = $this->actingAs($user, 'sanctum')->deleteJson("/api/folders/{$trash->id}/messages");

        $response->assertOk();
        $response->assertJson(['deleted' => 2]);
        $this->assertDatabaseMissing('messages', ['id' => $first->id]);
        $this->assertDatabaseMissing('messages', ['id' => $second->id]);
    }

    public function test_emptying_trash_with_no_messages_is_a_noop(): void
    {
        $user = User::factory()->create();
        $account = EmailAccount::factory()->for($user)->create();
        $trash = Folder::factory()->for($account)->create(['name' => 'Trash', 'type' => 'trash']);

        $response = $this->actingAs($user, 'sanctum')->deleteJson("/api/folders/{$trash->id}/messages");

        $response->assertOk();
        $response->assertJson(['deleted' => 0]);
    }

    public function test_emptying_a_non_trash_folder_is_rejected(): void
    {
        $user = User::factory()->create();
        $account = EmailAccount::factory()->for($user)->create();
        $inbox = Folder::factory()->for($account)->create(['type' => 'inbox']);
        Message::factory()->for($inbox)->create();

        $response = $this->actingAs($user, 'sanctum')->deleteJson("/api/folders/{$inbox->id}/messages");

        $response->assertStatus(422);
    }
}
