<?php

declare(strict_types=1);

namespace App\Services\Mail;

use App\Exceptions\Mail\ImapAuthenticationException;
use App\Exceptions\Mail\ImapConnectionException;
use App\Models\Folder;
use App\Models\Message;

/**
 * Moves a message from its current folder to another folder in the same
 * account — the general case behind both a user dragging a message onto a
 * folder and `MessageDeletionService::moveToTrash()`'s Trash-specific move.
 * Remote-first, same discipline as every other IMAP write in this app
 * (CLAUDE.md §3.3/§8 item 20): the local row only moves after the server
 * confirms it.
 */
final class MessageMoveService
{
    public function __construct(private readonly ImapConnectionFactory $connectionFactory)
    {
        //
    }

    /**
     * @throws ImapAuthenticationException when the stored credentials are no longer valid
     * @throws ImapConnectionException when the server cannot be reached, the folder/message no
     *                                 longer exists, or it reports an error
     */
    public function moveTo(Message $message, Folder $targetFolder): Message
    {
        $sourceFolder = $message->folder;
        $emailAccount = $sourceFolder->emailAccount;

        $client = $this->connectionFactory->connectForAccount($emailAccount);

        try {
            $imapFolder = $this->connectionFactory->guard(fn () => $client->getFolderByPath($sourceFolder->name));

            if ($imapFolder === null) {
                throw new ImapConnectionException("Folder \"{$sourceFolder->name}\" no longer exists on the mail server.");
            }

            $imapMessage = $this->connectionFactory->guard(
                fn () => $imapFolder->query()->whereUid($message->uid)->leaveUnread()->get()->first(),
            );

            if ($imapMessage === null) {
                throw new ImapConnectionException('The message no longer exists on the mail server.');
            }

            $moved = $this->connectionFactory->guard(fn () => $imapMessage->move($targetFolder->name, expunge: true));

            if ($moved === null) {
                throw new ImapConnectionException("Could not move the message to \"{$targetFolder->display_name}\".");
            }

            $message->update([
                'folder_id' => $targetFolder->id,
                'uid' => (int) $moved->uid,
            ]);
        } finally {
            $this->connectionFactory->disconnect($client);
        }

        return $message->refresh();
    }
}
