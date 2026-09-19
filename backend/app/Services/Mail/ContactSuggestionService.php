<?php

declare(strict_types=1);

namespace App\Services\Mail;

use App\Models\EmailAccount;
use App\Models\Message;
use Illuminate\Support\Str;

/**
 * Recipient/sender autocomplete — deliberately *not* backed by a dedicated
 * `contacts` table (CLAUDE.md §1: no scope creep beyond what a phase needs).
 * Every address the account has ever sent to or received from is already
 * sitting in `messages.from_name`/`from_email`/`to`/`cc` (§3.3), so a
 * suggestion list is just a live query over that — always current with no
 * separate data to keep in sync, matching the same "derive from what's
 * already synced" pattern as the Starred and Search views (§8 items 49/70).
 */
final class ContactSuggestionService
{
    /** How many matching messages to scan for candidate addresses. Capped so
     * this stays a fast, local-DB-only query even on a large mailbox. */
    private const CANDIDATE_MESSAGE_LIMIT = 300;

    private const MAX_SUGGESTIONS = 8;

    /**
     * @return list<array{name: ?string, email: string}>
     */
    public function suggest(EmailAccount $emailAccount, string $term): array
    {
        $likeTerm = '%'.$term.'%';

        $candidates = Message::query()
            ->whereHas('folder', fn ($query) => $query->where('email_account_id', $emailAccount->id))
            ->where(function ($query) use ($likeTerm) {
                $query->where('from_email', 'like', $likeTerm)
                    ->orWhere('from_name', 'like', $likeTerm)
                    ->orWhere('to', 'like', $likeTerm)
                    ->orWhere('cc', 'like', $likeTerm);
            })
            ->orderByDesc('date')
            ->limit(self::CANDIDATE_MESSAGE_LIMIT)
            ->get(['from_name', 'from_email', 'to', 'cc', 'date']);

        /** @var array<string, array{name: ?string, email: string, count: int, last_used_at: string}> $byEmail */
        $byEmail = [];
        $ownEmail = Str::lower($emailAccount->email);

        foreach ($candidates as $message) {
            $addresses = [
                ['name' => $message->from_name, 'email' => $message->from_email],
                ...($message->to ?? []),
                ...($message->cc ?? []),
            ];

            foreach ($addresses as $address) {
                $email = $address['email'] ?? null;

                if ($email === null || $email === '' || Str::lower($email) === $ownEmail) {
                    continue;
                }

                $name = $address['name'] ?? null;
                $matches = Str::contains(Str::lower($email), Str::lower($term))
                    || ($name !== null && Str::contains(Str::lower($name), Str::lower($term)));

                if (! $matches) {
                    continue;
                }

                $key = Str::lower($email);
                $existing = $byEmail[$key] ?? null;

                $byEmail[$key] = [
                    'name' => $name ?: $existing['name'] ?? null,
                    'email' => $email,
                    'count' => ($existing['count'] ?? 0) + 1,
                    'last_used_at' => $existing['last_used_at'] ?? $message->date->toIso8601String(),
                ];
            }
        }

        $entries = array_values($byEmail);

        usort($entries, function (array $a, array $b): int {
            return $b['count'] <=> $a['count'] ?: $b['last_used_at'] <=> $a['last_used_at'];
        });

        return array_map(
            fn (array $entry) => ['name' => $entry['name'], 'email' => $entry['email']],
            array_slice($entries, 0, self::MAX_SUGGESTIONS),
        );
    }
}
