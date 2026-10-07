<?php

declare(strict_types=1);

namespace Tests\Feature\Messages;

use App\Models\EmailAccount;
use App\Models\Folder;
use App\Models\Message;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\Concerns\InteractsWithImapMocks;
use Tests\TestCase;
use Webklex\PHPIMAP\ClientManager;

class MessageControllerTest extends TestCase
{
    use InteractsWithImapMocks;
    use RefreshDatabase;

    public function test_message_list_is_paginated_and_ordered_by_date_desc(): void
    {
        $user = User::factory()->create();
        $account = EmailAccount::factory()->for($user)->create();
        $folder = Folder::factory()->for($account)->create();

        $older = Message::factory()->for($folder)->create(['date' => now()->subDays(2)]);
        $newer = Message::factory()->for($folder)->create(['date' => now()->subDay()]);

        $response = $this->actingAs($user, 'sanctum')->getJson("/api/folders/{$folder->id}/messages");

        $response->assertOk();
        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertSame([$newer->id, $older->id], $ids);
    }

    public function test_starred_messages_span_every_folder_in_the_account_and_exclude_unflagged(): void
    {
        $user = User::factory()->create();
        $account = EmailAccount::factory()->for($user)->create();
        $inbox = Folder::factory()->for($account)->create(['name' => 'INBOX', 'type' => 'inbox']);
        $sent = Folder::factory()->for($account)->create(['name' => 'Sent', 'type' => 'sent']);
        $otherAccount = EmailAccount::factory()->for($user)->create();
        $otherFolder = Folder::factory()->for($otherAccount)->create();

        $starredInInbox = Message::factory()->for($inbox)->create(['is_flagged' => true, 'date' => now()->subDay()]);
        $starredInSent = Message::factory()->for($sent)->create(['is_flagged' => true, 'date' => now()]);
        Message::factory()->for($inbox)->create(['is_flagged' => false]);
        Message::factory()->for($otherFolder)->create(['is_flagged' => true]);

        $response = $this->actingAs($user, 'sanctum')->getJson("/api/email-accounts/{$account->id}/starred-messages");

        $response->assertOk();
        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertSame([$starredInSent->id, $starredInInbox->id], $ids);
    }

    public function test_a_user_cannot_view_another_users_starred_messages(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $account = EmailAccount::factory()->for($other)->create();

        $response = $this->actingAs($user, 'sanctum')->getJson("/api/email-accounts/{$account->id}/starred-messages");

        $response->assertForbidden();
    }

    public function test_message_list_summary_excludes_body_html_and_includes_preview(): void
    {
        $user = User::factory()->create();
        $account = EmailAccount::factory()->for($user)->create();
        $folder = Folder::factory()->for($account)->create();
        Message::factory()->for($folder)->create([
            'body_text' => 'Hello world, this is the message body.',
            'body_html' => '<p>Hello world</p>',
        ]);

        $response = $this->actingAs($user, 'sanctum')->getJson("/api/folders/{$folder->id}/messages");

        $response->assertOk();
        $response->assertJsonMissingPath('data.0.body_html');
        $this->assertStringContainsString('Hello world', $response->json('data.0.preview'));
    }

    public function test_a_user_cannot_list_another_users_folder_messages(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $account = EmailAccount::factory()->for($other)->create();
        $folder = Folder::factory()->for($account)->create();

        $response = $this->actingAs($user, 'sanctum')->getJson("/api/folders/{$folder->id}/messages");

        $response->assertForbidden();
    }

    public function test_first_view_of_a_message_fetches_and_caches_the_html_body(): void
    {
        $user = User::factory()->create();
        $account = EmailAccount::factory()->for($user)->create();
        $folder = Folder::factory()->for($account)->create();
        $message = Message::factory()->for($folder)->create(['uid' => 5, 'body_html' => null, 'is_read' => false]);

        $htmlMessage = $this->mockImapMessage(['uid' => 5], ['hasHTMLBody' => true, 'getHTMLBody' => '<p>Full body</p>']);
        $htmlMessage->shouldReceive('setFlag')->with('Seen')->once()->andReturnTrue();
        $this->mockFolderSync(uidValidity: 1, allUids: [5], messagesToReturn: [$htmlMessage]);

        $response = $this->actingAs($user, 'sanctum')->getJson("/api/messages/{$message->id}");

        $response->assertOk()->assertJsonPath('body_html', '<p>Full body</p>')->assertJsonPath('is_read', true);
        $this->assertSame('<p>Full body</p>', $message->fresh()->body_html);
        // Real-world fix: marking read now piggybacks on this same IMAP
        // connection instead of the frontend triggering a second one via
        // PATCH .../flags (CLAUDE.md §8) — so opening a previously-unread
        // message no longer pays for two full IMAP handshakes.
        $this->assertTrue($message->fresh()->is_read);
    }

    public function test_subsequent_view_of_a_message_uses_the_cached_html_without_contacting_imap(): void
    {
        $user = User::factory()->create();
        $account = EmailAccount::factory()->for($user)->create();
        $folder = Folder::factory()->for($account)->create();
        $message = Message::factory()->for($folder)->create(['body_html' => '<p>Already cached</p>']);

        $this->mock(ClientManager::class, function (MockInterface $mock) {
            $mock->shouldReceive('make')->never();
        });

        $response = $this->actingAs($user, 'sanctum')->getJson("/api/messages/{$message->id}");

        $response->assertOk()->assertJsonPath('body_html', '<p>Already cached</p>');
    }

    public function test_a_user_cannot_view_another_users_message(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $account = EmailAccount::factory()->for($other)->create();
        $folder = Folder::factory()->for($account)->create();
        $message = Message::factory()->for($folder)->create();

        $response = $this->actingAs($user, 'sanctum')->getJson("/api/messages/{$message->id}");

        $response->assertForbidden();
    }

    public function test_updating_flags_sets_remote_flag_before_the_local_row(): void
    {
        $user = User::factory()->create();
        $account = EmailAccount::factory()->for($user)->create();
        $folder = Folder::factory()->for($account)->create();
        $message = Message::factory()->for($folder)->create(['uid' => 7, 'is_read' => false, 'is_flagged' => false]);

        $imapMessage = $this->mockImapMessage(['uid' => 7]);
        $imapMessage->shouldReceive('setFlag')->with('Seen')->once()->andReturnTrue();
        $imapMessage->shouldReceive('setFlag')->with('Flagged')->once()->andReturnTrue();
        $this->mockFolderSync(uidValidity: 1, allUids: [7], messagesToReturn: [$imapMessage]);

        $response = $this->actingAs($user, 'sanctum')->patchJson("/api/messages/{$message->id}/flags", [
            'is_read' => true,
            'is_flagged' => true,
        ]);

        $response->assertOk()->assertJsonPath('is_read', true)->assertJsonPath('is_flagged', true);
        $this->assertTrue($message->fresh()->is_read);
        $this->assertTrue($message->fresh()->is_flagged);
    }

    public function test_updating_flags_leaves_local_state_unchanged_when_the_remote_call_fails(): void
    {
        $user = User::factory()->create();
        $account = EmailAccount::factory()->for($user)->create();
        $folder = Folder::factory()->for($account)->create();
        $message = Message::factory()->for($folder)->create(['uid' => 9, 'is_read' => false]);

        $imapMessage = $this->mockImapMessage(['uid' => 9]);
        $imapMessage->shouldReceive('setFlag')->with('Seen')->andThrow(new \RuntimeException('boom'));
        $this->mockFolderSync(uidValidity: 1, allUids: [9], messagesToReturn: [$imapMessage]);

        $response = $this->actingAs($user, 'sanctum')->patchJson("/api/messages/{$message->id}/flags", [
            'is_read' => true,
        ]);

        $response->assertStatus(503);
        $this->assertFalse($message->fresh()->is_read);
    }

    public function test_updating_flags_requires_at_least_one_flag(): void
    {
        $user = User::factory()->create();
        $account = EmailAccount::factory()->for($user)->create();
        $folder = Folder::factory()->for($account)->create();
        $message = Message::factory()->for($folder)->create();

        $response = $this->actingAs($user, 'sanctum')->patchJson("/api/messages/{$message->id}/flags", []);

        $response->assertUnprocessable();
    }

    public function test_a_user_cannot_update_flags_on_another_users_message(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $account = EmailAccount::factory()->for($other)->create();
        $folder = Folder::factory()->for($account)->create();
        $message = Message::factory()->for($folder)->create();

        $response = $this->actingAs($user, 'sanctum')->patchJson("/api/messages/{$message->id}/flags", ['is_read' => true]);

        $response->assertForbidden();
    }
}
