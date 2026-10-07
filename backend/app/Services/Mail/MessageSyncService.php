<?php

declare(strict_types=1);

namespace App\Services\Mail;

use App\Exceptions\Mail\ImapAuthenticationException;
use App\Exceptions\Mail\ImapConnectionException;
use App\Models\Folder;
use App\Models\Message;
use App\Models\MessageAttachment;
use Illuminate\Support\Collection;
use Webklex\PHPIMAP\Attachment;
use Webklex\PHPIMAP\Attribute;
use Webklex\PHPIMAP\Message as ImapMessage;

final class MessageSyncService
{
    /**
     * How many of the most recent messages to pull on a folder's very first
     * sync (or after its UIDVALIDITY changes and everything has to be
     * re-fetched). Kept small so the request stays well within typical PHP/
     * web-server timeouts (CLAUDE.md §3.5).
     */
    private const INITIAL_BATCH_SIZE = 30;

    public function __construct(private readonly ImapConnectionFactory $connectionFactory)
    {
        //
    }

    /**
     * Incrementally sync a folder's messages from the live IMAP server.
     *
     * @return Collection<int, Message> the messages that were fetched in this run
     *
     * @throws ImapAuthenticationException when the stored credentials are no longer valid
     * @throws ImapConnectionException when the server cannot be reached, the folder no longer
     *                                 exists, or it reports an error
     */
    public function sync(Folder $folder): Collection
    {
        $client = $this->connectionFactory->connectForAccount($folder->emailAccount);
        $syncedMessages = new Collection;

        try {
            $imapFolder = $this->connectionFactory->guard(fn () => $client->getFolderByPath($folder->name));

            if ($imapFolder === null) {
                throw new ImapConnectionException("Folder \"{$folder->name}\" no longer exists on the mail server.");
            }

            $status = $this->connectionFactory->guard(fn () => $imapFolder->examine());
            $currentUidValidity = (int) ($status['uidvalidity'] ?? 0);

            $needsFullResync = $folder->uid_validity === null || $folder->uid_validity !== $currentUidValidity;
            $lastUid = $needsFullResync ? 0 : $folder->last_uid;

            $allUids = $this->connectionFactory->guard(
                fn () => collect($imapFolder->query()->whereAll()->leaveUnread()->search()->all())
                    ->map(fn (mixed $uid): int => (int) $uid)
                    ->sort()
                    ->values()
                    ->all(),
            );

            $targetUids = $lastUid === 0
                ? array_slice($allUids, -self::INITIAL_BATCH_SIZE)
                : array_values(array_filter($allUids, fn (int $uid): bool => $uid > $lastUid));

            if ($targetUids !== []) {
                $imapMessages = $this->connectionFactory->guard(
                    fn () => $imapFolder->query()->whereUidIn($targetUids)->leaveUnread()->get(),
                );

                foreach ($imapMessages as $imapMessage) {
                    $syncedMessages->push($this->persistMessage($folder, $imapMessage));
                }
            }

            $folder->uid_validity = $currentUidValidity;
            $folder->last_uid = $targetUids === [] ? $lastUid : max($lastUid, max($targetUids));
            $folder->last_synced_at = now();
            $folder->save();
        } finally {
            $this->connectionFactory->disconnect($client);
        }

        return $syncedMessages;
    }

    private function persistMessage(Folder $folder, ImapMessage $imapMessage): Message
    {
        $attachments = $imapMessage->hasAttachments() ? $imapMessage->getAttachments() : collect();
        $from = $imapMessage->from?->first();

        $message = Message::query()->updateOrCreate(
            ['folder_id' => $folder->id, 'uid' => (int) $imapMessage->uid],
            [
                'rfc_message_id' => $imapMessage->message_id?->first(),
                'in_reply_to' => $imapMessage->in_reply_to?->first(),
                'subject' => $imapMessage->subject?->first(),
                'from_name' => $from?->personal ?: null,
                'from_email' => $from?->mail,
                'to' => $this->mapAddresses($imapMessage->to),
                'cc' => $this->mapAddresses($imapMessage->cc),
                'bcc' => $this->mapAddresses($imapMessage->bcc),
                'date' => $imapMessage->date?->toDate() ?? now(),
                'body_text' => $imapMessage->hasTextBody() ? $imapMessage->getTextBody() : null,
                'is_read' => $imapMessage->hasFlag('Seen'),
                'is_flagged' => $imapMessage->hasFlag('Flagged'),
                'has_attachments' => $attachments->isNotEmpty(),
            ],
        );

        foreach ($attachments as $attachment) {
            $this->persistAttachment($message, $attachment);
        }

        return $message;
    }

    private function persistAttachment(Message $message, Attachment $attachment): void
    {
        MessageAttachment::query()->updateOrCreate(
            ['message_id' => $message->id, 'part_number' => (string) $attachment->getPartNumber()],
            [
                'filename' => $attachment->getFilename() ?? $attachment->getName() ?? 'attachment',
                'mime_type' => $attachment->getContentType() ?? 'application/octet-stream',
                'size_bytes' => (int) ($attachment->getSize() ?? 0),
            ],
        );
    }

    /**
     * @return list<array{name: ?string, email: ?string}>
     */
    private function mapAddresses(?Attribute $attribute): array
    {
        if ($attribute === null) {
            return [];
        }

        return collect($attribute->all())
            ->map(fn ($address) => [
                'name' => $address->personal !== '' ? $address->personal : null,
                'email' => $address->mail !== '' ? $address->mail : null,
            ])
            ->all();
    }
}
