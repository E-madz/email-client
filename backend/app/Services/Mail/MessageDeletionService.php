<?php

declare(strict_types=1);

namespace App\Services\Mail;

use App\Exceptions\Mail\ImapAuthenticationException;
use App\Exceptions\Mail\ImapConnectionException;
use App\Models\Folder;
use App\Models\Message;
use Illuminate\Support\Collection;

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
    public function __construct(
        private readonly ImapConnectionFactory $connectionFactory,
        private readonly MessageMoveService $messageMoveService,
    ) {
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
        $trashFolder = $message->folder->emailAccount->folders()->where('type', 'trash')->first();

        if ($trashFolder === null) {
            throw new ImapConnectionException('No Trash folder found for this account yet — refresh the folder list first.');
        }

        return $this->messageMoveService->moveTo($message, $trashFolder);
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

    /**
     * Bulk equivalent of `destroy()`'s context-aware behavior — a
     * multi-select delete action. Each message is judged by its *own*
     * current folder (a bulk selection can span folders via the Starred/
     * Search views, possibly mixing Trash and non-Trash messages in one
     * batch): already-in-Trash messages are permanently deleted, everything
     * else is moved to Trash. A failure on one message/folder never aborts
     * the rest of the batch — collected in `failed` instead of thrown.
     *
     * Opens exactly one IMAP connection per email account involved, same
     * reasoning as `MessageFlagService::setFlagsForMany()`. Permanent
     * deletes within the same real Trash folder share a single `expunge()`
     * call at the end, mirroring `emptyTrash()`'s optimization, rather than
     * one `EXPUNGE` per message.
     *
     * @param  Collection<int, Message>  $messages
     * @return array{trashed: Collection<int, Message>, deletedCount: int, failed: list<array{id: int, reason: string}>}
     */
    public function deleteMany(Collection $messages): array
    {
        $trashed = new Collection;
        $deletedCount = 0;
        $failed = [];

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

                        if ($folder->type === 'trash') {
                            $toDelete = [];

                            foreach ($folderMessages as $message) {
                                $imapMessage = $byUid->get($message->uid);

                                // Already gone server-side is fine — deleting
                                // the local row still achieves the intent.
                                if ($imapMessage !== null) {
                                    $this->connectionFactory->guard(fn () => $imapMessage->setFlag('Deleted'));
                                }

                                $toDelete[] = $message->id;
                            }

                            $this->connectionFactory->guard(fn () => $client->expunge());
                            Message::query()->whereIn('id', $toDelete)->delete();
                            $deletedCount += count($toDelete);

                            continue;
                        }

                        $trashFolder = $emailAccount->folders()->where('type', 'trash')->first();

                        if ($trashFolder === null) {
                            foreach ($folderMessages as $message) {
                                $failed[] = ['id' => $message->id, 'reason' => 'No Trash folder found for this account yet — refresh the folder list first.'];
                            }

                            continue;
                        }

                        $existing = $folderMessages->filter(fn (Message $message) => $byUid->has($message->uid))->values();

                        foreach ($folderMessages as $message) {
                            if (! $byUid->has($message->uid)) {
                                $failed[] = ['id' => $message->id, 'reason' => 'The message no longer exists on the mail server.'];
                            }
                        }

                        if ($existing->isEmpty()) {
                            continue;
                        }

                        // Delegated to MessageMoveService — a single batched
                        // IMAP MOVE for the whole group instead of one
                        // Message::move() call per message (see its
                        // moveExistingMessages() docblock for why that
                        // wrapper is expensive per call).
                        $result = $this->messageMoveService->moveExistingMessages($client, $folder, $trashFolder, $existing);
                        $trashed->push(...$result['moved']->all());

                        foreach ($result['unconfirmed'] as $message) {
                            $failed[] = ['id' => $message->id, 'reason' => 'Could not confirm the message\'s new location after moving it to Trash.'];
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

        return ['trashed' => $trashed, 'deletedCount' => $deletedCount, 'failed' => $failed];
    }
}
