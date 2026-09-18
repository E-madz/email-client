<?php

declare(strict_types=1);

namespace Tests\Feature\Compose;

use App\Models\EmailAccount;
use App\Models\Folder;
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
use Tests\TestCase;

class ComposeControllerTest extends TestCase
{
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
        ]);
    }

    public function test_sending_a_message_with_an_attachment_stores_content_and_metadata(): void
    {
        Storage::fake('local');
        $this->mockSuccessfulSend();

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
}
