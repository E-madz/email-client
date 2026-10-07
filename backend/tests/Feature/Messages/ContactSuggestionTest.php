<?php

declare(strict_types=1);

namespace Tests\Feature\Messages;

use App\Models\EmailAccount;
use App\Models\Folder;
use App\Models\Message;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ContactSuggestionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_suggests_a_sender_matching_the_query_by_name(): void
    {
        $user = User::factory()->create();
        $account = EmailAccount::factory()->for($user)->create();
        $inbox = Folder::factory()->for($account)->create(['type' => 'inbox']);
        Message::factory()->for($inbox)->create([
            'from_name' => 'Ali Rezaei',
            'from_email' => 'ali@example.com',
            'to' => [],
            'cc' => [],
        ]);

        $response = $this->actingAs($user, 'sanctum')->getJson("/api/email-accounts/{$account->id}/contacts?q=ali");

        $response->assertOk();
        $this->assertSame(
            [['name' => 'Ali Rezaei', 'email' => 'ali@example.com']],
            $response->json(),
        );
    }

    public function test_it_suggests_a_recipient_matching_the_query_by_email(): void
    {
        $user = User::factory()->create();
        $account = EmailAccount::factory()->for($user)->create();
        $sent = Folder::factory()->for($account)->create(['type' => 'sent']);
        Message::factory()->for($sent)->create([
            'from_name' => null,
            'from_email' => $account->email,
            'to' => [['name' => 'Bob Carter', 'email' => 'bob@example.com']],
            'cc' => [['name' => null, 'email' => 'carol@example.com']],
        ]);

        $response = $this->actingAs($user, 'sanctum')->getJson("/api/email-accounts/{$account->id}/contacts?q=example.com");

        $response->assertOk();
        $emails = collect($response->json())->pluck('email')->all();
        $this->assertContains('bob@example.com', $emails);
        $this->assertContains('carol@example.com', $emails);
    }

    public function test_it_excludes_the_accounts_own_address(): void
    {
        $user = User::factory()->create();
        $account = EmailAccount::factory()->for($user)->create(['email' => 'me@example.com']);
        $sent = Folder::factory()->for($account)->create(['type' => 'sent']);
        Message::factory()->for($sent)->create([
            'from_name' => null,
            'from_email' => $account->email,
            'to' => [['name' => null, 'email' => 'me@example.com']],
        ]);

        $response = $this->actingAs($user, 'sanctum')->getJson("/api/email-accounts/{$account->id}/contacts?q=example.com");

        $response->assertOk();
        $this->assertSame([], $response->json());
    }

    public function test_it_deduplicates_the_same_address_and_ranks_more_frequent_ones_first(): void
    {
        $user = User::factory()->create();
        $account = EmailAccount::factory()->for($user)->create();
        $inbox = Folder::factory()->for($account)->create(['type' => 'inbox']);

        Message::factory()->for($inbox)->create(['from_name' => 'Ali', 'from_email' => 'ali@example.com', 'to' => [], 'cc' => []]);
        Message::factory()->for($inbox)->create(['from_name' => 'Ali', 'from_email' => 'ali@example.com', 'to' => [], 'cc' => []]);
        Message::factory()->for($inbox)->create(['from_name' => 'Alireza', 'from_email' => 'alireza@example.com', 'to' => [], 'cc' => []]);

        $response = $this->actingAs($user, 'sanctum')->getJson("/api/email-accounts/{$account->id}/contacts?q=ali");

        $response->assertOk();
        $emails = collect($response->json())->pluck('email')->all();
        $this->assertSame('ali@example.com', $emails[0]);
        $this->assertCount(2, $emails);
    }

    public function test_it_excludes_messages_from_other_accounts(): void
    {
        $user = User::factory()->create();
        $account = EmailAccount::factory()->for($user)->create();
        $otherAccount = EmailAccount::factory()->for($user)->create();
        $otherInbox = Folder::factory()->for($otherAccount)->create(['type' => 'inbox']);
        Message::factory()->for($otherInbox)->create(['from_name' => 'Ali', 'from_email' => 'ali@example.com', 'to' => [], 'cc' => []]);

        $response = $this->actingAs($user, 'sanctum')->getJson("/api/email-accounts/{$account->id}/contacts?q=ali");

        $response->assertOk();
        $this->assertSame([], $response->json());
    }

    public function test_it_requires_ownership_of_the_email_account(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $otherAccount = EmailAccount::factory()->for($other)->create();

        $response = $this->actingAs($user, 'sanctum')->getJson("/api/email-accounts/{$otherAccount->id}/contacts?q=ali");

        $response->assertForbidden();
    }

    public function test_it_requires_a_non_empty_query(): void
    {
        $user = User::factory()->create();
        $account = EmailAccount::factory()->for($user)->create();

        $response = $this->actingAs($user, 'sanctum')->getJson("/api/email-accounts/{$account->id}/contacts");

        $response->assertStatus(422);
    }
}
