<?php

declare(strict_types=1);

namespace Tests\Feature\Messages;

use App\Models\EmailAccount;
use App\Models\Folder;
use App\Models\Message;
use App\Models\User;
use Database\Seeders\MailServerSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MessageSearchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(MailServerSeeder::class);
    }

    public function test_search_matches_subject_across_folders(): void
    {
        $user = User::factory()->create();
        $account = EmailAccount::factory()->for($user)->create();
        $inbox = Folder::factory()->for($account)->create(['name' => 'INBOX', 'type' => 'inbox']);
        $archive = Folder::factory()->for($account)->create(['name' => 'Archive', 'type' => 'archive']);
        $match1 = Message::factory()->for($inbox)->create(['subject' => 'Quarterly budget review']);
        $match2 = Message::factory()->for($archive)->create(['subject' => 'Re: budget numbers']);
        $noMatch = Message::factory()->for($inbox)->create(['subject' => 'Lunch tomorrow?']);

        $response = $this->actingAs($user, 'sanctum')->getJson(
            "/api/email-accounts/{$account->id}/search-messages?q=budget",
        );

        $response->assertOk();
        $ids = collect($response->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($match1->id));
        $this->assertTrue($ids->contains($match2->id));
        $this->assertFalse($ids->contains($noMatch->id));
    }

    public function test_search_matches_sender_name_and_email(): void
    {
        $user = User::factory()->create();
        $account = EmailAccount::factory()->for($user)->create();
        $inbox = Folder::factory()->for($account)->create(['type' => 'inbox']);
        $byName = Message::factory()->for($inbox)->create(['from_name' => 'Alice Johnson', 'from_email' => 'a@example.com']);
        $byEmail = Message::factory()->for($inbox)->create(['from_name' => 'Someone', 'from_email' => 'alice.j@example.com']);
        $noMatch = Message::factory()->for($inbox)->create(['from_name' => 'Bob', 'from_email' => 'bob@example.com']);

        $response = $this->actingAs($user, 'sanctum')->getJson(
            "/api/email-accounts/{$account->id}/search-messages?q=alice",
        );

        $ids = collect($response->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($byName->id));
        $this->assertTrue($ids->contains($byEmail->id));
        $this->assertFalse($ids->contains($noMatch->id));
    }

    public function test_search_matches_body_text(): void
    {
        $user = User::factory()->create();
        $account = EmailAccount::factory()->for($user)->create();
        $inbox = Folder::factory()->for($account)->create(['type' => 'inbox']);
        $match = Message::factory()->for($inbox)->create(['body_text' => 'Please find the invoice attached.']);
        $noMatch = Message::factory()->for($inbox)->create(['body_text' => 'See you at the meeting.']);

        $response = $this->actingAs($user, 'sanctum')->getJson(
            "/api/email-accounts/{$account->id}/search-messages?q=invoice",
        );

        $ids = collect($response->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($match->id));
        $this->assertFalse($ids->contains($noMatch->id));
    }

    /**
     * Recipients matter for a Sent/Drafts message, where "from" is always
     * the account's own address (§8 item 66) — searching for a recipient's
     * name should still find a message the account sent to them.
     */
    public function test_search_matches_recipients(): void
    {
        $user = User::factory()->create();
        $account = EmailAccount::factory()->for($user)->create();
        $sent = Folder::factory()->for($account)->create(['type' => 'sent']);
        $match = Message::factory()->for($sent)->create([
            'to' => [['name' => 'Carol Danvers', 'email' => 'carol@example.com']],
        ]);
        $noMatch = Message::factory()->for($sent)->create([
            'to' => [['name' => 'Bob', 'email' => 'bob@example.com']],
        ]);

        $response = $this->actingAs($user, 'sanctum')->getJson(
            "/api/email-accounts/{$account->id}/search-messages?q=carol",
        );

        $ids = collect($response->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($match->id));
        $this->assertFalse($ids->contains($noMatch->id));
    }

    public function test_search_results_are_ordered_by_date_descending(): void
    {
        $user = User::factory()->create();
        $account = EmailAccount::factory()->for($user)->create();
        $inbox = Folder::factory()->for($account)->create(['type' => 'inbox']);
        $older = Message::factory()->for($inbox)->create(['subject' => 'invoice old', 'date' => now()->subDays(2)]);
        $newer = Message::factory()->for($inbox)->create(['subject' => 'invoice new', 'date' => now()]);

        $response = $this->actingAs($user, 'sanctum')->getJson(
            "/api/email-accounts/{$account->id}/search-messages?q=invoice",
        );

        $ids = collect($response->json('data'))->pluck('id')->values();
        $this->assertSame([$newer->id, $older->id], $ids->all());
    }

    public function test_search_excludes_other_accounts_messages(): void
    {
        $user = User::factory()->create();
        $account = EmailAccount::factory()->for($user)->create();
        $otherAccount = EmailAccount::factory()->for($user)->create();
        $inbox = Folder::factory()->for($account)->create(['type' => 'inbox']);
        $otherInbox = Folder::factory()->for($otherAccount)->create(['type' => 'inbox']);
        Message::factory()->for($otherInbox)->create(['subject' => 'invoice from another account']);
        $match = Message::factory()->for($inbox)->create(['subject' => 'invoice here']);

        $response = $this->actingAs($user, 'sanctum')->getJson(
            "/api/email-accounts/{$account->id}/search-messages?q=invoice",
        );

        $ids = collect($response->json('data'))->pluck('id');
        $this->assertSame([$match->id], $ids->all());
    }

    public function test_a_user_cannot_search_another_users_account(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $account = EmailAccount::factory()->for($other)->create();

        $response = $this->actingAs($user, 'sanctum')->getJson(
            "/api/email-accounts/{$account->id}/search-messages?q=invoice",
        );

        $response->assertForbidden();
    }

    public function test_an_empty_query_is_rejected(): void
    {
        $user = User::factory()->create();
        $account = EmailAccount::factory()->for($user)->create();

        $response = $this->actingAs($user, 'sanctum')->getJson(
            "/api/email-accounts/{$account->id}/search-messages?q=",
        );

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['q']);
    }
}
