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
use Tests\TestCase;

class AttachmentControllerTest extends TestCase
{
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

    public function test_downloading_a_received_messages_attachment_without_storage_path_returns_501(): void
    {
        $user = User::factory()->create();
        $account = EmailAccount::factory()->for($user)->create();
        $folder = Folder::factory()->for($account)->create();
        $message = Message::factory()->for($folder)->create();
        $attachment = MessageAttachment::factory()->for($message)->create([
            'storage_path' => null,
            'part_number' => '2',
        ]);

        $response = $this->actingAs($user, 'sanctum')->get("/api/attachments/{$attachment->id}");

        $response->assertStatus(501);
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
