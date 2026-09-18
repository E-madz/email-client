<?php

declare(strict_types=1);

namespace App\Services\Mail;

use App\Exceptions\Mail\ImapAuthenticationException;
use App\Exceptions\Mail\ImapConnectionException;
use App\Models\EmailAccount;
use App\Models\Folder;
use Illuminate\Database\Eloquent\Collection;

final class FolderSyncService
{
    public function __construct(
        private readonly ImapConnectionFactory $connectionFactory,
        private readonly FolderTypeMapper $typeMapper,
    ) {
        //
    }

    /**
     * Refresh the account's folder list from the live IMAP server (upsert,
     * never delete — a folder removed on the server just stops being touched).
     *
     * @return Collection<int, Folder>
     *
     * @throws ImapAuthenticationException when the stored credentials are no longer valid
     * @throws ImapConnectionException when the server cannot be reached or reports an error
     */
    public function sync(EmailAccount $emailAccount): Collection
    {
        $client = $this->connectionFactory->connectForAccount($emailAccount);

        try {
            $rawFolders = $this->connectionFactory->guard(
                fn () => $client->getConnection()->folders('', '*')->validatedData(),
            );
        } finally {
            $this->connectionFactory->disconnect($client);
        }

        $mappedFolders = [];

        foreach ($rawFolders as $name => $item) {
            $delimiter = (string) ($item['delimiter'] ?? '/');
            $mapped = $this->typeMapper->map($item['flags'] ?? [], (string) $name, $delimiter);

            $mappedFolders[(string) $name] = [
                'type' => $mapped['type'],
                'display_name' => $mapped['display_name'],
                'is_inbox_child' => $delimiter !== '' && str_starts_with((string) $name, 'INBOX'.$delimiter),
            ];
        }

        $mappedFolders = $this->dedupeDuplicateTypes($mappedFolders);

        foreach ($mappedFolders as $name => $mapped) {
            Folder::query()->updateOrCreate(
                ['email_account_id' => $emailAccount->id, 'name' => $name],
                ['type' => $mapped['type'], 'display_name' => $mapped['display_name']],
            );
        }

        return $emailAccount->folders()
            ->withCount(['messages as unread_count' => fn ($query) => $query->where('is_read', false)])
            ->get();
    }

    /**
     * Real-world lesson (CLAUDE.md §8): some IranServer accounts carry two
     * folders that independently map to the same non-custom type — e.g. a
     * top-level "Junk" folder carrying the RFC 6154 SPECIAL-USE \Junk
     * attribute *and* a separate "INBOX.spam" child folder matched only by
     * name. The SPECIAL-USE flag isn't reliable as a tie-breaker here: on the
     * one real account this was found on, the folder actually used day-to-day
     * (confirmed against the same account's Roundcube folder tree) is the one
     * nested under INBOX, not the SPECIAL-USE-flagged top-level one. Rather
     * than show two duplicate-looking "هرزنامه" entries in the sidebar, keep
     * exactly one canonical folder per type — preferring an INBOX child, then
     * falling back to whichever came first — and demote every other same-type
     * folder to `custom` (its own raw name), never dropping any folder.
     *
     * @param  array<string, array{type: string, display_name: string, is_inbox_child: bool}>  $mappedFolders
     * @return array<string, array{type: string, display_name: string, is_inbox_child: bool}>
     */
    private function dedupeDuplicateTypes(array $mappedFolders): array
    {
        $canonicalNameByType = [];

        foreach ($mappedFolders as $name => $mapped) {
            if ($mapped['type'] === 'custom') {
                continue;
            }

            $currentCanonical = $canonicalNameByType[$mapped['type']] ?? null;

            if ($currentCanonical === null || ($mapped['is_inbox_child'] && ! $mappedFolders[$currentCanonical]['is_inbox_child'])) {
                $canonicalNameByType[$mapped['type']] = $name;
            }
        }

        foreach ($mappedFolders as $name => &$mapped) {
            if ($mapped['type'] !== 'custom' && $canonicalNameByType[$mapped['type']] !== $name) {
                $mapped['type'] = 'custom';
                $mapped['display_name'] = $name;
            }
        }

        return $mappedFolders;
    }
}
