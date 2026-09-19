<?php

declare(strict_types=1);

namespace App\Services\Mail;

use App\Exceptions\Mail\ImapAuthenticationException;
use App\Exceptions\Mail\ImapConnectionException;
use App\Models\Folder;
use App\Models\Message;
use App\Support\MessageMoveEligibility;
use Illuminate\Support\Collection;

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

    /**
     * Bulk equivalent of `moveTo()` — a multi-select "move to folder" action,
     * all landing in the same `$targetFolder`. Every message is first
     * checked against `MessageMoveEligibility` (the same rule the single-
     * message endpoint enforces); an ineligible one is skipped with a
     * reason rather than failing the whole batch, since a bulk selection can
     * legitimately mix eligible and ineligible messages (e.g. selecting
     * across the cross-folder Starred/Search views).
     *
     * Opens exactly one IMAP connection for the whole call (the target
     * account's) and switches between source folders on it as needed — the
     * same reasoning as `MessageFlagService::setFlagsForMany()`.
     *
     * @param  Collection<int, Message>  $messages
     * @return array{moved: Collection<int, Message>, skipped: list<array{id: int, reason: string}>}
     */
    public function moveManyTo(Collection $messages, Folder $targetFolder): array
    {
        $moved = new Collection;
        $skipped = [];
        $eligible = new Collection;

        foreach ($messages as $message) {
            $violation = MessageMoveEligibility::violation($message->folder, $targetFolder);

            if ($violation !== null) {
                $skipped[] = ['id' => $message->id, 'reason' => $violation];

                continue;
            }

            $eligible->push($message);
        }

        if ($eligible->isEmpty()) {
            return ['moved' => $moved, 'skipped' => $skipped];
        }

        try {
            $client = $this->connectionFactory->connectForAccount($targetFolder->emailAccount);
        } catch (ImapAuthenticationException|ImapConnectionException $e) {
            foreach ($eligible as $message) {
                $skipped[] = ['id' => $message->id, 'reason' => $e->getMessage()];
            }

            return ['moved' => $moved, 'skipped' => $skipped];
        }

        try {
            foreach ($eligible->groupBy('folder_id') as $sourceMessages) {
                $sourceFolder = $sourceMessages->first()->folder;

                try {
                    $imapFolder = $this->connectionFactory->guard(fn () => $client->getFolderByPath($sourceFolder->name));

                    if ($imapFolder === null) {
                        throw new ImapConnectionException("Folder \"{$sourceFolder->name}\" no longer exists on the mail server.");
                    }

                    $uids = $sourceMessages->pluck('uid')->all();
                    $imapMessages = $this->connectionFactory->guard(
                        fn () => $imapFolder->query()->whereUidIn($uids)->leaveUnread()->get(),
                    );
                    $byUid = collect($imapMessages)->keyBy(fn ($imapMessage): int => (int) $imapMessage->uid);
                    $movedAnyInThisFolder = false;

                    foreach ($sourceMessages as $message) {
                        $imapMessage = $byUid->get($message->uid);

                        if ($imapMessage === null) {
                            $skipped[] = ['id' => $message->id, 'reason' => 'The message no longer exists on the mail server.'];

                            continue;
                        }

                        // expunge: false here — moving N messages one at a
                        // time with expunge: true would issue N separate
                        // EXPUNGE round-trips to the server (each its own
                        // network round-trip on top of the COPY/STORE this
                        // move already needs), which is the dominant cost of
                        // a slow bulk move against a real mail server. A
                        // single expunge() below, once per source folder,
                        // purges every moved-out message's leftover copy in
                        // one command instead — the same optimization
                        // MessageDeletionService::emptyTrash() already uses.
                        $movedImapMessage = $this->connectionFactory->guard(
                            fn () => $imapMessage->move($targetFolder->name, expunge: false),
                        );

                        if ($movedImapMessage === null) {
                            $skipped[] = ['id' => $message->id, 'reason' => "Could not move the message to \"{$targetFolder->display_name}\"."];

                            continue;
                        }

                        $message->update([
                            'folder_id' => $targetFolder->id,
                            'uid' => (int) $movedImapMessage->uid,
                        ]);
                        $moved->push($message->refresh());
                        $movedAnyInThisFolder = true;
                    }

                    if ($movedAnyInThisFolder) {
                        $this->connectionFactory->guard(fn () => $client->expunge());
                    }
                } catch (ImapAuthenticationException|ImapConnectionException $e) {
                    foreach ($sourceMessages as $message) {
                        $skipped[] = ['id' => $message->id, 'reason' => $e->getMessage()];
                    }
                }
            }
        } finally {
            $this->connectionFactory->disconnect($client);
        }

        return ['moved' => $moved, 'skipped' => $skipped];
    }
}
