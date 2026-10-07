<?php

declare(strict_types=1);

namespace App\Services\Mail;

use App\Exceptions\Mail\ImapAuthenticationException;
use App\Exceptions\Mail\ImapConnectionException;
use App\Models\Folder;

/**
 * Deletes a real IMAP folder and mirrors that locally only once the server
 * confirms it, same remote-first discipline as folder creation. Deleting a
 * mailbox via IMAP removes it and everything inside it in one operation —
 * there is no server-side "move contents to Trash first" step, so a
 * non-empty folder's messages are gone for good along with it. The local
 * `messages` rows for this folder cascade-delete automatically
 * (`folder_id` FK, `cascadeOnDelete`) once the `Folder` row itself is
 * removed.
 */
final class FolderDeletionService
{
    public function __construct(private readonly ImapConnectionFactory $connectionFactory)
    {
        //
    }

    /**
     * @throws ImapAuthenticationException when the stored credentials are no longer valid
     * @throws ImapConnectionException when the server cannot be reached or the folder no
     *                                 longer exists
     */
    public function delete(Folder $folder): void
    {
        $client = $this->connectionFactory->connectForAccount($folder->emailAccount);

        try {
            // expunge: false for the same reason as FolderCreationService
            // (CLAUDE.md §8 item 54) — webklex's default issues an IMAP
            // EXPUNGE right after DELETE, which fails because no mailbox is
            // selected here, even though the DELETE itself already succeeded.
            $this->connectionFactory->guard(fn () => $client->deleteFolder($folder->name, expunge: false));
        } finally {
            $this->connectionFactory->disconnect($client);
        }

        $folder->delete();
    }
}
