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
                'is_special_use' => $mapped['is_special_use'],
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
     * Real-world lesson (CLAUDE.md §8, revised by item 57): some IranServer
     * accounts carry two folders that independently map to the same
     * non-custom type — e.g. a top-level "Junk" folder carrying the RFC 6154
     * SPECIAL-USE \Junk attribute *and* a separate "INBOX.spam" child folder
     * matched only by name. An earlier version of this method preferred the
     * INBOX child as canonical, based on it looking like "the" spam folder on
     * one account — but the user later confirmed directly against Roundcube
     * that the *opposite* is true: Roundcube (and the mail server itself)
     * only refuses to delete the SPECIAL-USE-flagged "Junk" folder, and
     * happily lets a real folder deletion remove "INBOX.spam" — proving the
     * name-only match was never actually a protected system folder, just a
     * regularly-named one. The SPECIAL-USE attribute, read straight from the
     * IMAP protocol, is the authoritative signal for "is this a real system
     * folder" — never a name guess. So the canonical folder for a type is
     * always the SPECIAL-USE-flagged one when one exists; a name-only match
     * only wins when nothing else claims that type. Every other same-type
     * folder is demoted to `custom` (its own raw name), never dropped.
     *
     * @param  array<string, array{type: string, display_name: string, is_special_use: bool}>  $mappedFolders
     * @return array<string, array{type: string, display_name: string, is_special_use: bool}>
     */
    private function dedupeDuplicateTypes(array $mappedFolders): array
    {
        $canonicalNameByType = [];

        foreach ($mappedFolders as $name => $mapped) {
            if ($mapped['type'] === 'custom') {
                continue;
            }

            $currentCanonical = $canonicalNameByType[$mapped['type']] ?? null;

            if ($currentCanonical === null || ($mapped['is_special_use'] && ! $mappedFolders[$currentCanonical]['is_special_use'])) {
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
