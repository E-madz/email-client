<?php

declare(strict_types=1);

namespace Tests\Feature\Compose;

use App\Models\EmailAccount;
use App\Models\Folder;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Models\User;
use App\Services\Mail\SmtpTransportManager;
use Database\Seeders\MailServerSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Mockery\MockInterface;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Tests\Concerns\InteractsWithImapMocks;
use Tests\TestCase;
use Webklex\PHPIMAP\Client;
use Webklex\PHPIMAP\ClientManager;
use Webklex\PHPIMAP\Exceptions\ConnectionFailedException;

class ComposeControllerTest extends TestCase
{
    use InteractsWithImapMocks;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(MailServerSeeder::class);
    }

    private function mockTransport(): MockInterface
    {
        $transport = \Mockery::mock(TransportInterface::class);

        $this->mock(SmtpTransportManager::class, function (MockInterface $mock) use ($transport) {
            $mock->shouldReceive('make')->andReturn($transport);
        });

        return $transport;
    }

    private function mockSuccessfulSend(): void
    {
        $this->mockTransport()->shouldReceive('send')->once();
    }

    public function test_sending_a_message_returns_201_and_records_a_local_sent_copy(): void
    {
        $this->mockSuccessfulSend();
        $this->mockSuccessfulImapAppend(uid: 777);

        $user = User::factory()->create();
        $account = EmailAccount::factory()->for($user)->create(['email' => 'me@example.com']);
        $sent = Folder::factory()->for($account)->create(['name' => 'Sent', 'type' => 'sent', 'display_name' => 'Sent']);

        $response = $this->actingAs($user, 'sanctum')->postJson("/api/email-accounts/{$account->id}/messages", [
            'to' => ['friend@example.com'],
            'cc' => ['cc@example.com'],
            'subject' => 'Hello there',
            'body_html' => '<p>Hi!</p>',
        ]);

        $response->assertCreated();
        $response->assertJsonPath('subject', 'Hello there');
        $response->assertJsonPath('from.email', 'me@example.com');

        $this->assertDatabaseHas('messages', [
            'folder_id' => $sent->id,
            'subject' => 'Hello there',
            'from_email' => 'me@example.com',
            'is_read' => true,
            // A real IMAP APPEND (Phase 4b) — the UID comes from the mocked
            // by-Message-ID lookup, not a synthetic timestamp.
            'uid' => 777,
        ]);
    }

    public function test_sending_a_reply_sets_threading_headers_and_stores_in_reply_to(): void
    {
        $this->mockSuccessfulSend();
        $this->mockSuccessfulImapAppend();

        $user = User::factory()->create();
        $account = EmailAccount::factory()->for($user)->create(['email' => 'me@example.com']);
        Folder::factory()->for($account)->create(['name' => 'Sent', 'type' => 'sent']);
        $inbox = Folder::factory()->for($account)->create(['name' => 'INBOX', 'type' => 'inbox']);
        $original = Message::factory()->for($inbox)->create(['rfc_message_id' => '<original@example.com>']);

        $response = $this->actingAs($user, 'sanctum')->postJson("/api/email-accounts/{$account->id}/messages", [
            'to' => ['friend@example.com'],
            'subject' => 'Re: Hello',
            'body_html' => '<p>Reply body</p>',
            'in_reply_to_message_id' => $original->id,
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('messages', [
            'subject' => 'Re: Hello',
            'in_reply_to' => '<original@example.com>',
        ]);
    }

    public function test_replying_to_a_message_from_another_account_is_rejected(): void
    {
        $user = User::factory()->create();
        $account = EmailAccount::factory()->for($user)->create();
        $otherAccount = EmailAccount::factory()->for($user)->create();
        $otherInbox = Folder::factory()->for($otherAccount)->create(['name' => 'INBOX']);
        $original = Message::factory()->for($otherInbox)->create();

        $response = $this->actingAs($user, 'sanctum')->postJson("/api/email-accounts/{$account->id}/messages", [
            'to' => ['friend@example.com'],
            'subject' => 'Re: Hello',
            'body_html' => '<p>Reply body</p>',
            'in_reply_to_message_id' => $original->id,
        ]);

        $response->assertStatus(422);
    }

    public function test_sending_a_message_with_an_attachment_stores_content_and_metadata(): void
    {
        Storage::fake('local');
        $this->mockSuccessfulSend();
        $this->mockSuccessfulImapAppend();

        $user = User::factory()->create();
        $account = EmailAccount::factory()->for($user)->create();
        Folder::factory()->for($account)->create(['name' => 'Sent', 'type' => 'sent']);

        $file = UploadedFile::fake()->create('invoice.pdf', 50, 'application/pdf');

        $response = $this->actingAs($user, 'sanctum')->postJson("/api/email-accounts/{$account->id}/messages", [
            'to' => ['friend@example.com'],
            'subject' => 'With attachment',
            'body_html' => '<p>See attached.</p>',
            'attachments' => [$file],
        ]);

        $response->assertCreated();
        $response->assertJsonPath('attachments.0.filename', 'invoice.pdf');

        $this->assertDatabaseHas('message_attachments', [
            'filename' => 'invoice.pdf',
            'mime_type' => 'application/pdf',
        ]);

        $attachment = MessageAttachment::where('filename', 'invoice.pdf')->firstOrFail();
        Storage::disk('local')->assertExists($attachment->storage_path);
    }

    public function test_smtp_auth_failure_returns_422_and_flags_needs_reauth(): void
    {
        // Real-world shape (CLAUDE.md §3.6/§8): Symfony Mailer doesn't throw a
        // typed auth exception, it folds the SMTP reply code into a generic
        // TransportException's message - this drives our own classification
        // logic in SmtpTransportFactory::isAuthenticationFailure(), not a stub.
        $this->mockTransport()->shouldReceive('send')->andThrow(
            new TransportException('Expected response code "250" but got code "535", with message "535 5.7.8 Authentication failed".'),
        );

        $user = User::factory()->create();
        $account = EmailAccount::factory()->for($user)->create(['status' => 'active']);

        $response = $this->actingAs($user, 'sanctum')->postJson("/api/email-accounts/{$account->id}/messages", [
            'to' => ['friend@example.com'],
            'subject' => 'Hi',
            'body_html' => '<p>Hi</p>',
        ]);

        $response->assertStatus(422);
        $this->assertSame('needs_reauth', $account->fresh()->status);
        $this->assertDatabaseMissing('messages', ['subject' => 'Hi']);
    }

    public function test_smtp_connection_failure_returns_503(): void
    {
        $this->mockTransport()->shouldReceive('send')->andThrow(
            new TransportException('Connection could not be established with host mail.iranserver.com.'),
        );

        $user = User::factory()->create();
        $account = EmailAccount::factory()->for($user)->create(['status' => 'active']);

        $response = $this->actingAs($user, 'sanctum')->postJson("/api/email-accounts/{$account->id}/messages", [
            'to' => ['friend@example.com'],
            'subject' => 'Hi',
            'body_html' => '<p>Hi</p>',
        ]);

        $response->assertStatus(503);
        $this->assertSame('active', $account->fresh()->status);
    }

    public function test_validation_requires_at_least_one_recipient_and_valid_emails(): void
    {
        $user = User::factory()->create();
        $account = EmailAccount::factory()->for($user)->create();

        $response = $this->actingAs($user, 'sanctum')->postJson("/api/email-accounts/{$account->id}/messages", [
            'to' => [],
            'subject' => 'Hi',
            'body_html' => '<p>Hi</p>',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['to']);
    }

    public function test_a_user_cannot_send_from_another_users_account(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $account = EmailAccount::factory()->for($other)->create();

        $response = $this->actingAs($user, 'sanctum')->postJson("/api/email-accounts/{$account->id}/messages", [
            'to' => ['friend@example.com'],
            'subject' => 'Hi',
            'body_html' => '<p>Hi</p>',
        ]);

        $response->assertForbidden();
    }

    public function test_sending_falls_back_to_a_local_only_sent_copy_when_the_imap_append_fails(): void
    {
        $this->mockSuccessfulSend();

        $client = \Mockery::mock(Client::class);
        $client->shouldReceive('connect')->andThrow(new ConnectionFailedException);
        $client->shouldReceive('disconnect')->andReturnSelf();
        $this->mock(ClientManager::class, function (MockInterface $mock) use ($client) {
            $mock->shouldReceive('make')->andReturn($client);
        });

        $user = User::factory()->create();
        $account = EmailAccount::factory()->for($user)->create();
        Folder::factory()->for($account)->create(['name' => 'Sent', 'type' => 'sent']);

        $response = $this->actingAs($user, 'sanctum')->postJson("/api/email-accounts/{$account->id}/messages", [
            'to' => ['friend@example.com'],
            'subject' => 'Append will fail',
            'body_html' => '<p>Hi</p>',
        ]);

        // The send itself already succeeded over SMTP — an IMAP append
        // failure never surfaces as an error to the user, it just falls
        // back to recording a local-only copy (§8 item 58).
        $response->assertCreated();
        $this->assertDatabaseHas('messages', ['subject' => 'Append will fail']);
    }

    public function test_sending_without_a_synced_sent_folder_still_succeeds(): void
    {
        $this->mockSuccessfulSend();

        $user = User::factory()->create();
        $account = EmailAccount::factory()->for($user)->create();

        $response = $this->actingAs($user, 'sanctum')->postJson("/api/email-accounts/{$account->id}/messages", [
            'to' => ['friend@example.com'],
            'subject' => 'No sent folder yet',
            'body_html' => '<p>Hi</p>',
        ]);

        $response->assertCreated();
        $this->assertDatabaseMissing('messages', ['subject' => 'No sent folder yet']);
    }

    /**
     * §8 item 65 — a sent draft is superseded by the real Sent copy `send()`
     * just recorded, so it's removed outright rather than left in Drafts.
     */
    public function test_sending_a_draft_permanently_removes_it_from_drafts(): void
    {
        $this->mockSuccessfulSend();
        $this->mockSuccessfulImapAppend();

        $user = User::factory()->create();
        $account = EmailAccount::factory()->for($user)->create();
        Folder::factory()->for($account)->create(['name' => 'Sent', 'type' => 'sent']);
        $drafts = Folder::factory()->for($account)->create(['name' => 'Drafts', 'type' => 'drafts']);
        $draft = Message::factory()->for($drafts)->create();

        $response = $this->actingAs($user, 'sanctum')->postJson("/api/email-accounts/{$account->id}/messages", [
            'to' => ['friend@example.com'],
            'subject' => 'Finally sending this',
            'body_html' => '<p>Hi</p>',
            'draft_message_id' => $draft->id,
        ]);

        $response->assertCreated();
        $this->assertDatabaseMissing('messages', ['id' => $draft->id]);
    }

    /**
     * Real-world lesson (§8 item 67): `postJson()` in the tests above sends
     * `draft_message_id` as a genuine JSON integer, but the real frontend
     * always submits this endpoint as `multipart/form-data` (it also accepts
     * file attachments), where every field — including this one — arrives as
     * a string even after the `integer` validation rule confirms it's
     * numeric. `deleteSentDraft(int $draftId)`'s strict type hint threw a
     * `TypeError` the moment a real browser request hit it. Reproduced here
     * via a genuine multipart request (`$this->call()`, not `postJson()`),
     * not the JSON shortcut every other test in this class uses.
     */
    public function test_sending_a_draft_via_a_real_multipart_request_removes_it(): void
    {
        $this->mockSuccessfulSend();
        $this->mockSuccessfulImapAppend();

        $user = User::factory()->create();
        $account = EmailAccount::factory()->for($user)->create();
        Folder::factory()->for($account)->create(['name' => 'Sent', 'type' => 'sent']);
        $drafts = Folder::factory()->for($account)->create(['name' => 'Drafts', 'type' => 'drafts']);
        $draft = Message::factory()->for($drafts)->create();

        $response = $this->actingAs($user, 'sanctum')->call(
            'POST',
            "/api/email-accounts/{$account->id}/messages",
            [
                'to' => ['friend@example.com'],
                'subject' => 'Finally sending this',
                'body_html' => '<p>Hi</p>',
                'draft_message_id' => (string) $draft->id,
            ],
            [],
            [],
            ['HTTP_Accept' => 'application/json'],
        );

        $response->assertCreated();
        $this->assertDatabaseMissing('messages', ['id' => $draft->id]);
    }

    public function test_a_draft_from_another_account_cannot_be_referenced_when_sending(): void
    {
        $user = User::factory()->create();
        $account = EmailAccount::factory()->for($user)->create();
        $otherAccount = EmailAccount::factory()->for($user)->create();
        $otherDrafts = Folder::factory()->for($otherAccount)->create(['name' => 'Drafts', 'type' => 'drafts']);
        $draft = Message::factory()->for($otherDrafts)->create();

        $response = $this->actingAs($user, 'sanctum')->postJson("/api/email-accounts/{$account->id}/messages", [
            'to' => ['friend@example.com'],
            'subject' => 'Hi',
            'body_html' => '<p>Hi</p>',
            'draft_message_id' => $draft->id,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['draft_message_id']);
    }

    public function test_a_non_draft_message_cannot_be_referenced_as_draft_message_id(): void
    {
        $user = User::factory()->create();
        $account = EmailAccount::factory()->for($user)->create();
        $inbox = Folder::factory()->for($account)->create(['name' => 'INBOX', 'type' => 'inbox']);
        $message = Message::factory()->for($inbox)->create();

        $response = $this->actingAs($user, 'sanctum')->postJson("/api/email-accounts/{$account->id}/messages", [
            'to' => ['friend@example.com'],
            'subject' => 'Hi',
            'body_html' => '<p>Hi</p>',
            'draft_message_id' => $message->id,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['draft_message_id']);
    }
}
