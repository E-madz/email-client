<?php

declare(strict_types=1);

namespace Tests\Feature\Attachments;

use App\Models\EmailAccount;
use App\Models\Folder;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Models\User;
use Database\Seeders\MailServerSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\InteractsWithImapMocks;
use Tests\TestCase;
use Webklex\PHPIMAP\Attachment as ImapAttachment;
use Webklex\PHPIMAP\Client;
use Webklex\PHPIMAP\ClientManager;
use Webklex\PHPIMAP\Exceptions\AuthFailedException;

class AttachmentControllerTest extends TestCase
{
    use InteractsWithImapMocks;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(MailServerSeeder::class);
    }

    public function test_downloading_a_composed_messages_attachment(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('attachments/invoice.pdf', 'PDF content');

        $user = User::factory()->create();
        $account = EmailAccount::factory()->for($user)->create();
        $folder = Folder::factory()->for($account)->create(['type' => 'sent']);
        $message = Message::factory()->for($folder)->create();
        $attachment = MessageAttachment::factory()->for($message)->create([
            'filename' => 'invoice.pdf',
            'storage_path' => 'attachments/invoice.pdf',
            'part_number' => null,
        ]);

        $response = $this->actingAs($user, 'sanctum')->get("/api/attachments/{$attachment->id}");

        $response->assertOk();
        $response->assertDownload('invoice.pdf');
    }

    public function test_downloading_a_received_messages_attachment_fetches_it_live_from_imap(): void
    {
        $user = User::factory()->create();
        $account = EmailAccount::factory()->for($user)->create();
        $folder = Folder::factory()->for($account)->create();
        $message = Message::factory()->for($folder)->create(['uid' => 5]);
        $attachment = MessageAttachment::factory()->for($message)->create([
            'filename' => 'photo.png',
            'mime_type' => 'image/png',
            'storage_path' => null,
            'part_number' => '2',
        ]);

        $imapAttachment = \Mockery::mock(ImapAttachment::class);
        $imapAttachment->shouldReceive('getPartNumber')->andReturn('2');
        $imapAttachment->shouldReceive('getContent')->andReturn('raw-binary-content');
        $imapAttachment->shouldReceive('getContentType')->andReturn('image/png');

        $imapMessage = $this->mockImapMessage(['uid' => 5], ['getAttachments' => collect([$imapAttachment])]);
        $this->mockFolderSync(uidValidity: 1, allUids: [5], messagesToReturn: [$imapMessage]);

        $response = $this->actingAs($user, 'sanctum')->get("/api/attachments/{$attachment->id}");

        $response->assertOk();
        $response->assertContent('raw-binary-content');
        $response->assertHeader('Content-Type', 'image/png');
    }

    public function test_downloading_a_received_messages_attachment_maps_imap_auth_failure_to_422(): void
    {
        $client = \Mockery::mock(Client::class);
        $client->shouldReceive('connect')->andThrow(new AuthFailedException);
        $client->shouldReceive('disconnect')->andReturnSelf();

        $this->mock(ClientManager::class, function ($mock) use ($client) {
            $mock->shouldReceive('make')->andReturn($client);
        });

        $user = User::factory()->create();
        $account = EmailAccount::factory()->for($user)->create(['status' => 'active']);
        $folder = Folder::factory()->for($account)->create();
        $message = Message::factory()->for($folder)->create();
        $attachment = MessageAttachment::factory()->for($message)->create(['storage_path' => null]);

        $response = $this->actingAs($user, 'sanctum')->get("/api/attachments/{$attachment->id}");

        $response->assertStatus(422);
        $this->assertSame('needs_reauth', $account->fresh()->status);
    }

    public function test_a_user_cannot_download_another_users_attachment(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $account = EmailAccount::factory()->for($other)->create();
        $folder = Folder::factory()->for($account)->create();
        $message = Message::factory()->for($folder)->create();
        $attachment = MessageAttachment::factory()->for($message)->create();

        $response = $this->actingAs($user, 'sanctum')->get("/api/attachments/{$attachment->id}");

        $response->assertForbidden();
    }
}
