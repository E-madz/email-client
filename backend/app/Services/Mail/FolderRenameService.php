<?php

declare(strict_types=1);

namespace App\Services\Mail;

use App\Exceptions\Mail\ImapAuthenticationException;
use App\Exceptions\Mail\ImapConnectionException;
use App\Models\Folder;

/**
 * Renames a real IMAP folder — a mailbox `RENAME`, mirrored locally as
 * `name`/`display_name` only once the server confirms it, same remote-first
 * discipline as `FolderCreationService`/`FolderDeletionService`. Restricted
 * to `type === 'custom'` at the controller level, same guard as deletion.
 */
final class FolderRenameService
{
    public function __construct(private readonly ImapConnectionFactory $connectionFactory)
    {
        //
    }

    /**
     * @throws ImapAuthenticationException when the stored credentials are no longer valid
     * @throws ImapConnectionException when the server cannot be reached, the folder no longer
     *                                 exists, or it rejects the new name (e.g. already taken)
     */
    public function rename(Folder $folder, string $newName): Folder
    {
        $client = $this->connectionFactory->connectForAccount($folder->emailAccount);

        try {
            $imapFolder = $this->connectionFactory->guard(fn () => $client->getFolderByPath($folder->name));

            if ($imapFolder === null) {
                throw new ImapConnectionException("Folder \"{$folder->name}\" no longer exists on the mail server.");
            }

            // expunge: false — the same webklex-wrapper caveat as folder
            // create/delete (§8 items 54/55): the default issues a doomed
            // post-RENAME EXPUNGE with no mailbox selected, which would turn
            // a successful rename into a false-negative error response.
            $renamed = $this->connectionFactory->guard(fn () => $imapFolder->rename($newName, expunge: false));

            if ($renamed === null) {
                throw new ImapConnectionException("Could not rename the folder to \"{$newName}\" on the mail server.");
            }
        } finally {
            $this->connectionFactory->disconnect($client);
        }

        $folder->update(['name' => $newName, 'display_name' => $newName]);

        return $folder->refresh();
    }
}
