<?php

declare(strict_types=1);

namespace Tests\Feature\Folders;

use App\Models\EmailAccount;
use App\Models\Folder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithImapMocks;
use Tests\TestCase;
use Webklex\PHPIMAP\Attachment;

class MessageSyncTest extends TestCase
{
    use InteractsWithImapMocks;
    use RefreshDatabase;

    public function test_first_sync_fetches_only_the_most_recent_batch(): void
    {
        $user = User::factory()->create();
        $account = EmailAccount::factory()->for($user)->create();
        $folder = Folder::factory()->for($account)->create(['uid_validity' => null, 'last_uid' => 0]);

        $allUids = range(1, 40);
        $recentMessages = [
            $this->mockImapMessage(['uid' => 39, 'subject' => $this->imapAttribute('Thirty nine'), 'to' => $this->imapAddressAttribute([]), 'date' => $this->imapAttribute(now())]),
            $this->mockImapMessage(['uid' => 40, 'subject' => $this->imapAttribute('Forty'), 'to' => $this->imapAddressAttribute([]), 'date' => $this->imapAttribute(now())]),
        ];
        $this->mockFolderSync(uidValidity: 111, allUids: $allUids, messagesToReturn: $recentMessages);

        $response = $this->actingAs($user, 'sanctum')->postJson("/api/folders/{$folder->id}/sync");

        $response->assertOk();
        $this->assertSame(111, $folder->fresh()->uid_validity);
        $this->assertSame(40, $folder->fresh()->last_uid);
        $this->assertDatabaseCount('messages', 2);
        $this->assertDatabaseHas('messages', ['folder_id' => $folder->id, 'uid' => 40, 'subject' => 'Forty']);
    }

    public function test_incremental_sync_only_fetches_uids_after_last_uid(): void
    {
        $user = User::factory()->create();
        $account = EmailAccount::factory()->for($user)->create();
        $folder = Folder::factory()->for($account)->create(['uid_validity' => 111, 'last_uid' => 40]);

        $allUids = range(1, 42);
        $newMessages = [
            $this->mockImapMessage(['uid' => 41, 'subject' => $this->imapAttribute('Forty one'), 'to' => $this->imapAddressAttribute([]), 'date' => $this->imapAttribute(now())]),
            $this->mockImapMessage(['uid' => 42, 'subject' => $this->imapAttribute('Forty two'), 'to' => $this->imapAddressAttribute([]), 'date' => $this->imapAttribute(now())]),
        ];
        $this->mockFolderSync(uidValidity: 111, allUids: $allUids, messagesToReturn: $newMessages);

        $response = $this->actingAs($user, 'sanctum')->postJson("/api/folders/{$folder->id}/sync");

        $response->assertOk()->assertJsonCount(2);
        $this->assertSame(42, $folder->fresh()->last_uid);
    }

    public function test_a_changed_uid_validity_triggers_a_full_resync(): void
    {
        $user = User::factory()->create();
        $account = EmailAccount::factory()->for($user)->create();
        // Folder previously synced up to uid 40 under an old uid_validity.
        $folder = Folder::factory()->for($account)->create(['uid_validity' => 111, 'last_uid' => 40]);

        // Server now reports a new uid_validity with a fresh, smaller UID space.
        $allUids = range(1, 5);
        $messages = [
            $this->mockImapMessage(['uid' => 5, 'subject' => $this->imapAttribute('New world'), 'to' => $this->imapAddressAttribute([]), 'date' => $this->imapAttribute(now())]),
        ];
        $this->mockFolderSync(uidValidity: 222, allUids: $allUids, messagesToReturn: $messages);

        $response = $this->actingAs($user, 'sanctum')->postJson("/api/folders/{$folder->id}/sync");

        $response->assertOk();
        $this->assertSame(222, $folder->fresh()->uid_validity);
        $this->assertSame(5, $folder->fresh()->last_uid);
    }

    public function test_syncing_stores_attachment_metadata_without_content(): void
    {
        $user = User::factory()->create();
        $account = EmailAccount::factory()->for($user)->create();
        $folder = Folder::factory()->for($account)->create(['uid_validity' => null, 'last_uid' => 0]);

        $attachment = \Mockery::mock(Attachment::class);
        $attachment->shouldReceive('getFilename')->andReturn('invoice.pdf');
        $attachment->shouldReceive('getContentType')->andReturn('application/pdf');
        $attachment->shouldReceive('getSize')->andReturn(12345);
        $attachment->shouldReceive('getPartNumber')->andReturn('2');

        $message = $this->mockImapMessage(
            ['uid' => 1, 'subject' => $this->imapAttribute('Has attachment'), 'to' => $this->imapAddressAttribute([]), 'date' => $this->imapAttribute(now())],
            ['hasAttachments' => true, 'getAttachments' => collect([$attachment])],
        );

        $this->mockFolderSync(uidValidity: 1, allUids: [1], messagesToReturn: [$message]);

        $response = $this->actingAs($user, 'sanctum')->postJson("/api/folders/{$folder->id}/sync");

        $response->assertOk();
        $this->assertDatabaseHas('message_attachments', [
            'filename' => 'invoice.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 12345,
            'part_number' => '2',
        ]);
    }

    public function test_a_user_cannot_sync_another_users_folder(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $account = EmailAccount::factory()->for($other)->create();
        $folder = Folder::factory()->for($account)->create();

        $response = $this->actingAs($user, 'sanctum')->postJson("/api/folders/{$folder->id}/sync");

        $response->assertForbidden();
    }
}
