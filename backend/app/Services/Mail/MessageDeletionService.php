<?php

declare(strict_types=1);

namespace App\Services\Mail;

use App\Exceptions\Mail\ImapAuthenticationException;
use App\Exceptions\Mail\ImapConnectionException;
use App\Models\Folder;
use App\Models\Message;

/**
 * Deleting a message is context-aware, same as every real webmail client:
 * from anywhere except Trash it's a move to Trash (recoverable); from
 * inside Trash (or the bulk "empty trash" action) it's permanent. Every
 * IMAP-side change here is mirrored locally only after the server confirms
 * it, same remote-first pattern as MessageFlagService (CLAUDE.md §3.3/§8
 * item 20).
 */
final class MessageDeletionService
{
    public function __construct(private readonly ImapConnectionFactory $connectionFactory)
    {
        //
    }

    /**
     * @throws ImapAuthenticationException when the stored credentials are no longer valid
     * @throws ImapConnectionException when the server cannot be reached, the account has no synced
     *                                 Trash folder yet, the message/folder no longer exists, or the
     *                                 server reports an error
     */
    public function moveToTrash(Message $message): Message
    {
        $folder = $message->folder;
        $emailAccount = $folder->emailAccount;

        $trashFolder = $emailAccount->folders()->where('type', 'trash')->first();

        if ($trashFolder === null) {
            throw new ImapConnectionException('No Trash folder found for this account yet — refresh the folder list first.');
        }

        $client = $this->connectionFactory->connectForAccount($emailAccount);

        try {
            $imapFolder = $this->connectionFactory->guard(fn () => $client->getFolderByPath($folder->name));

            if ($imapFolder === null) {
                throw new ImapConnectionException("Folder \"{$folder->name}\" no longer exists on the mail server.");
            }

            $imapMessage = $this->connectionFactory->guard(
                fn () => $imapFolder->query()->whereUid($message->uid)->leaveUnread()->get()->first(),
            );

            if ($imapMessage === null) {
                throw new ImapConnectionException('The message no longer exists on the mail server.');
            }

            $moved = $this->connectionFactory->guard(fn () => $imapMessage->move($trashFolder->name, expunge: true));

            if ($moved === null) {
                throw new ImapConnectionException('Could not move the message to Trash.');
            }

            $message->update([
                'folder_id' => $trashFolder->id,
                'uid' => (int) $moved->uid,
            ]);
        } finally {
            $this->connectionFactory->disconnect($client);
        }

        return $message->refresh();
    }

    /**
     * @throws ImapAuthenticationException when the stored credentials are no longer valid
     * @throws ImapConnectionException when the server cannot be reached or reports an error
     */
    public function deletePermanently(Message $message): void
    {
        $folder = $message->folder;
        $client = $this->connectionFactory->connectForAccount($folder->emailAccount);

        try {
            $imapFolder = $this->connectionFactory->guard(fn () => $client->getFolderByPath($folder->name));

            if ($imapFolder !== null) {
                $imapMessage = $this->connectionFactory->guard(
                    fn () => $imapFolder->query()->whereUid($message->uid)->leaveUnread()->get()->first(),
                );

                // Already gone server-side (e.g. deleted elsewhere) is fine —
                // deleting the local row still achieves the user's intent.
                if ($imapMessage !== null) {
                    $this->connectionFactory->guard(fn () => $imapMessage->delete(expunge: true));
                }
            }
        } finally {
            $this->connectionFactory->disconnect($client);
        }

        $message->delete();
    }

    /**
     * @throws ImapAuthenticationException when the stored credentials are no longer valid
     * @throws ImapConnectionException when the server cannot be reached or reports an error
     */
    public function emptyTrash(Folder $trashFolder): int
    {
        $messages = $trashFolder->messages()->get();

        if ($messages->isEmpty()) {
            return 0;
        }

        $client = $this->connectionFactory->connectForAccount($trashFolder->emailAccount);

        try {
            $imapFolder = $this->connectionFactory->guard(fn () => $client->getFolderByPath($trashFolder->name));

            if ($imapFolder !== null) {
                foreach ($messages as $message) {
                    $imapMessage = $this->connectionFactory->guard(
                        fn () => $imapFolder->query()->whereUid($message->uid)->leaveUnread()->get()->first(),
                    );

                    if ($imapMessage !== null) {
                        $this->connectionFactory->guard(fn () => $imapMessage->setFlag('Deleted'));
                    }
                }

                $this->connectionFactory->guard(fn () => $client->expunge());
            }
        } finally {
            $this->connectionFactory->disconnect($client);
        }

        $trashFolder->messages()->delete();

        return $messages->count();
    }
}
