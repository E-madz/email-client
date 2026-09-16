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

        foreach ($rawFolders as $name => $item) {
            $mapped = $this->typeMapper->map($item['flags'] ?? [], (string) $name, (string) ($item['delimiter'] ?? '/'));

            Folder::query()->updateOrCreate(
                ['email_account_id' => $emailAccount->id, 'name' => $name],
                ['type' => $mapped['type'], 'display_name' => $mapped['display_name']],
            );
        }

        return $emailAccount->folders()
            ->withCount(['messages as unread_count' => fn ($query) => $query->where('is_read', false)])
            ->get();
    }
}
