<?php

declare(strict_types=1);

namespace App\Services\Mail;

use App\Exceptions\Mail\ImapAuthenticationException;
use App\Exceptions\Mail\ImapConnectionException;
use App\Models\Message;

final class MessageFlagService
{
    public function __construct(private readonly ImapConnectionFactory $connectionFactory)
    {
        //
    }

    /**
     * Set a message's read/flagged state. The IMAP server is always updated
     * first; the local row is only changed if that succeeds, so the two
     * never drift apart on a failure (CLAUDE.md §3.5/§5, Phase 2 decision).
     *
     * @throws ImapAuthenticationException when the stored credentials are no longer valid
     * @throws ImapConnectionException when the server cannot be reached, the folder/message no
     *                                 longer exists, or it reports an error
     */
    public function setFlags(Message $message, ?bool $isRead, ?bool $isFlagged): Message
    {
        if ($isRead === null && $isFlagged === null) {
            return $message;
        }

        $folder = $message->folder;
        $client = $this->connectionFactory->connectForAccount($folder->emailAccount);

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

            if ($isRead !== null) {
                $this->connectionFactory->guard(
                    fn () => $isRead ? $imapMessage->setFlag('Seen') : $imapMessage->unsetFlag('Seen'),
                );
                $message->is_read = $isRead;
            }

            if ($isFlagged !== null) {
                $this->connectionFactory->guard(
                    fn () => $isFlagged ? $imapMessage->setFlag('Flagged') : $imapMessage->unsetFlag('Flagged'),
                );
                $message->is_flagged = $isFlagged;
            }

            $message->save();
        } finally {
            $this->connectionFactory->disconnect($client);
        }

        return $message;
    }
}
