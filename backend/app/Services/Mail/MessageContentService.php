<?php

declare(strict_types=1);

namespace App\Services\Mail;

use App\Exceptions\Mail\ImapAuthenticationException;
use App\Exceptions\Mail\ImapConnectionException;
use App\Models\Message;
use App\Models\MessageAttachment;

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
     * Real-world lesson: the frontend marks a message read right after
     * opening it (CLAUDE.md §8 item 30), which used to mean a *second*,
     * separate IMAP connection (via MessageFlagService) immediately after
     * this one — doubling the TCP+TLS+LOGIN round-trip latency to a real
     * mail server on every first-open of an unread message. The Seen flag
     * is now set on this same already-open connection/message instead, so
     * the response this returns already carries is_read = true and the
     * frontend's follow-up flags call becomes a no-op it correctly skips.
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

            $dirty = false;

            if ($imapMessage->hasHTMLBody()) {
                $message->body_html = $imapMessage->getHTMLBody();
                $dirty = true;
            }

            if (! $message->is_read) {
                $this->connectionFactory->guard(fn () => $imapMessage->setFlag('Seen'));
                $message->is_read = true;
                $dirty = true;
            }

            if ($dirty) {
                $message->save();
            }
        } finally {
            $this->connectionFactory->disconnect($client);
        }

        return $message;
    }

    /**
     * Fetch a received message's attachment content live from IMAP by UID +
     * part number, per the original Phase 2 design (CLAUDE.md §3.3: "a real
     * download fetches the part live from IMAP ... when actually requested",
     * never persisted). Not cached — each download re-fetches, same as any
     * other IMAP-backed read in this app; attachments are typically opened
     * rarely enough that this is simpler than adding a caching layer.
     *
     * @return array{content: string, mime_type: string}
     *
     * @throws ImapAuthenticationException when the stored credentials are no longer valid
     * @throws ImapConnectionException when the server cannot be reached, the folder/message/attachment
     *                                 no longer exists, or it reports an error
     */
    public function fetchAttachmentContent(MessageAttachment $attachment): array
    {
        $message = $attachment->message;
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

            $imapAttachment = $this->connectionFactory->guard(
                fn () => $imapMessage->getAttachments()->first(
                    fn ($candidate) => (string) $candidate->getPartNumber() === (string) $attachment->part_number,
                ),
            );

            if ($imapAttachment === null) {
                throw new ImapConnectionException('The attachment no longer exists on the mail server.');
            }

            return [
                'content' => $imapAttachment->getContent(),
                'mime_type' => $imapAttachment->getContentType() ?? $attachment->mime_type,
            ];
        } finally {
            $this->connectionFactory->disconnect($client);
        }
    }
}
