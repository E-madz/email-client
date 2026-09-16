<?php

declare(strict_types=1);

namespace App\Services\Mail;

use App\Exceptions\Mail\ImapAuthenticationException;
use App\Exceptions\Mail\ImapConnectionException;
use App\Models\Message;

final class MessageContentService
{
    public function __construct(private readonly ImapConnectionFactory $connectionFactory)
    {
        //
    }

    /**
     * Lazily fetch and cache a message's HTML body the first time it's
     * requested (CLAUDE.md §5, Phase 2 decision — keeps folder sync fast by
     * not downloading every message's full HTML body upfront).
     *
     * @throws ImapAuthenticationException when the stored credentials are no longer valid
     * @throws ImapConnectionException when the server cannot be reached, the folder/message no
     *                                 longer exists, or it reports an error
     */
    public function ensureBodyHtmlLoaded(Message $message): Message
    {
        if ($message->body_html !== null) {
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

            if ($imapMessage->hasHTMLBody()) {
                $message->body_html = $imapMessage->getHTMLBody();
                $message->save();
            }
        } finally {
            $this->connectionFactory->disconnect($client);
        }

        return $message;
    }
}
