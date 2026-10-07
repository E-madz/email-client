<?php

declare(strict_types=1);

namespace App\Services\Mail;

use App\Exceptions\Mail\ImapAuthenticationException;
use App\Exceptions\Mail\ImapConnectionException;
use App\Models\Message;
use Illuminate\Support\Collection;

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

    /**
     * Bulk equivalent of `setFlags()` — a multi-select "mark read"/"star"
     * action. Unlike the single-message version, a failure on one message
     * never aborts the whole batch: each failure is collected and returned
     * instead of thrown, since a bulk action against a real mail server is
     * expected to partially succeed (one message gone, one folder briefly
     * unreachable) without losing the rest of the batch.
     *
     * Opens exactly **one IMAP connection per email account** involved (not
     * one per message, and not even one per folder) — the expensive part of
     * an IMAP round-trip is the TCP+TLS+LOGIN handshake (CLAUDE.md §3.6), not
     * the `SELECT`/`STORE` commands after it, so a single already-open
     * connection is reused across every folder a selection spans (relevant
     * for a bulk action taken from the cross-folder Starred/Search views).
     *
     * @param  Collection<int, Message>  $messages
     * @return array{updated: Collection<int, Message>, failed: list<array{id: int, reason: string}>}
     */
    public function setFlagsForMany(Collection $messages, ?bool $isRead, ?bool $isFlagged): array
    {
        $updated = new Collection;
        $failed = [];

        if ($isRead === null && $isFlagged === null) {
            return ['updated' => $updated, 'failed' => $failed];
        }

        foreach ($messages->groupBy(fn (Message $message): int => $message->folder->email_account_id) as $accountMessages) {
            $emailAccount = $accountMessages->first()->folder->emailAccount;

            try {
                $client = $this->connectionFactory->connectForAccount($emailAccount);
            } catch (ImapAuthenticationException|ImapConnectionException $e) {
                foreach ($accountMessages as $message) {
                    $failed[] = ['id' => $message->id, 'reason' => $e->getMessage()];
                }

                continue;
            }

            try {
                foreach ($accountMessages->groupBy('folder_id') as $folderMessages) {
                    $folder = $folderMessages->first()->folder;

                    try {
                        $imapFolder = $this->connectionFactory->guard(fn () => $client->getFolderByPath($folder->name));

                        if ($imapFolder === null) {
                            throw new ImapConnectionException("Folder \"{$folder->name}\" no longer exists on the mail server.");
                        }

                        $uids = $folderMessages->pluck('uid')->all();
                        $imapMessages = $this->connectionFactory->guard(
                            fn () => $imapFolder->query()->whereUidIn($uids)->leaveUnread()->get(),
                        );
                        $byUid = collect($imapMessages)->keyBy(fn ($imapMessage): int => (int) $imapMessage->uid);

                        foreach ($folderMessages as $message) {
                            $imapMessage = $byUid->get($message->uid);

                            if ($imapMessage === null) {
                                $failed[] = ['id' => $message->id, 'reason' => 'The message no longer exists on the mail server.'];

                                continue;
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
                            $updated->push($message);
                        }
                    } catch (ImapAuthenticationException|ImapConnectionException $e) {
                        foreach ($folderMessages as $message) {
                            $failed[] = ['id' => $message->id, 'reason' => $e->getMessage()];
                        }
                    }
                }
            } finally {
                $this->connectionFactory->disconnect($client);
            }
        }

        return ['updated' => $updated, 'failed' => $failed];
    }
}
