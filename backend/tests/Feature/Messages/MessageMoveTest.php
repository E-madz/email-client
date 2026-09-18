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

        $movedImapMessage = $this->mockImapMessage(['uid' => 99]);
        $imapMessage = $this->mockImapMessage(['uid' => 5]);
        $imapMessage->shouldReceive('move')->with('Archive', expunge: true)->once()->andReturn($movedImapMessage);

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
}
