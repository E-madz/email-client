<?php

declare(strict_types=1);

namespace App\Services\Mail;

use App\Exceptions\Mail\ImapAuthenticationException;
use App\Exceptions\Mail\ImapConnectionException;
use App\Models\Folder;
use App\Models\Message;
use App\Support\MessageMoveEligibility;
use Illuminate\Support\Collection;
use Webklex\PHPIMAP\Client;

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

            $result = $this->moveExistingMessages($client, $sourceFolder, $targetFolder, new Collection([$message]));

            if ($result['moved']->isEmpty()) {
                throw new ImapConnectionException("Could not move the message to \"{$targetFolder->display_name}\".");
            }
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

                    $existing = $sourceMessages->filter(fn (Message $message) => $byUid->has($message->uid))->values();

                    foreach ($sourceMessages as $message) {
                        if (! $byUid->has($message->uid)) {
                            $skipped[] = ['id' => $message->id, 'reason' => 'The message no longer exists on the mail server.'];
                        }
                    }

                    if ($existing->isEmpty()) {
                        continue;
                    }

                    $result = $this->moveExistingMessages($client, $sourceFolder, $targetFolder, $existing);
                    $moved->push(...$result['moved']->all());

                    foreach ($result['unconfirmed'] as $message) {
                        $skipped[] = ['id' => $message->id, 'reason' => "Could not confirm the message's new location after moving it to \"{$targetFolder->display_name}\"."];
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

    /**
     * Moves a group of messages — all already confirmed to exist in
     * `$sourceFolder` (e.g. via a prior `whereUidIn()` fetch) — into
     * `$targetFolder` with exactly **one** batched IMAP `MOVE` command for
     * the whole group, instead of looping `Message::move()` once per
     * message. That per-message wrapper alone issues ~6-7 round-trips
     * (re-selecting the target folder, EXAMINEing it, re-selecting the
     * source, the move itself, then re-selecting the target again just to
     * FETCH the moved message back) — for N messages that's O(N) round-
     * trips, the dominant cost of a slow move against a real mail server.
     * `webklex/php-imap`'s raw connection exposes `moveManyMessages()`,
     * which builds a single `UID MOVE u1,u2,... folder` command for an
     * entire UID list (falling back to a single COPY + STORE + EXPUNGE
     * itself if the server doesn't support the MOVE extension) — the same
     * "go around the wrapper, straight to the raw connection" pattern this
     * codebase already uses in FolderSyncService (CLAUDE.md §3.6).
     *
     * Since neither `moveManyMessages()` nor this library exposes the
     * RFC 4315 COPYUID response (the protocol's own explicit old→new UID
     * mapping), the new UIDs are instead recovered the same trusted way
     * `SmtpMailService`/`DraftService` already do for a fresh APPEND (§8
     * item 61): read the target folder's `UIDNEXT` *before* moving, then
     * confirm which of the next N UIDs actually exist afterward via one
     * more search — never a guess, never a search "by content" (the kind
     * of lookup §8 item 58 already proved unreliable on a real server).
     * Messages are paired to new UIDs in ascending UID order on both sides,
     * matching how a sequence set is canonically processed. A message
     * whose new UID can't be confirmed this way is reported back as
     * `unconfirmed` rather than guessed at — its local row is left
     * untouched, so nothing is ever silently pointed at the wrong message.
     *
     * Public so `MessageDeletionService::deleteMany()` can reuse it for its
     * move-to-trash branch on the single IMAP connection it already holds
     * open, rather than duplicating this logic.
     *
     * @param  Collection<int, Message>  $existingMessages  messages confirmed to currently exist in $sourceFolder
     * @return array{moved: Collection<int, Message>, unconfirmed: Collection<int, Message>}
     */
    public function moveExistingMessages(Client $client, Folder $sourceFolder, Folder $targetFolder, Collection $existingMessages): array
    {
        $sorted = $existingMessages->sortBy('uid')->values();
        $uids = $sorted->pluck('uid')->map(fn ($uid): int => (int) $uid)->all();

        // EXAMINEing the target folder to read UIDNEXT switches the
        // connection's currently-selected folder to it — the source folder
        // has to be re-selected afterward before issuing the move, since
        // MOVE/COPY/STORE all operate against whatever folder is currently
        // selected.
        $targetImapFolder = $this->connectionFactory->guard(fn () => $client->getFolderByPath($targetFolder->name));

        if ($targetImapFolder === null) {
            throw new ImapConnectionException("Folder \"{$targetFolder->name}\" no longer exists on the mail server.");
        }

        $targetStatus = $this->connectionFactory->guard(fn () => $targetImapFolder->examine());
        $startUid = (int) ($targetStatus['uidnext'] ?? 0);

        $sourceImapFolder = $this->connectionFactory->guard(fn () => $client->getFolderByPath($sourceFolder->name));

        if ($sourceImapFolder === null) {
            throw new ImapConnectionException("Folder \"{$sourceFolder->name}\" no longer exists on the mail server.");
        }

        $this->connectionFactory->guard(fn () => $sourceImapFolder->examine());

        $succeeded = $this->connectionFactory->guard(
            fn () => $client->getConnection()->moveManyMessages($uids, $targetFolder->name)->boolean(),
        );

        if (! $succeeded || $startUid <= 0) {
            return ['moved' => new Collection, 'unconfirmed' => $sorted];
        }

        $targetImapFolder = $this->connectionFactory->guard(fn () => $client->getFolderByPath($targetFolder->name));
        $allTargetUids = $this->connectionFactory->guard(
            fn () => collect($targetImapFolder->query()->whereAll()->leaveUnread()->search()->all())
                ->map(fn (mixed $uid): int => (int) $uid),
        );

        $newUids = $allTargetUids
            ->filter(fn (int $uid): bool => $uid >= $startUid)
            ->sort()
            ->values()
            ->take($sorted->count());

        $moved = new Collection;
        $unconfirmed = new Collection;

        foreach ($sorted->values() as $index => $message) {
            $newUid = $newUids->get($index);

            if ($newUid === null) {
                $unconfirmed->push($message);

                continue;
            }

            $message->update(['folder_id' => $targetFolder->id, 'uid' => $newUid]);
            $moved->push($message->refresh());
        }

        return ['moved' => $moved, 'unconfirmed' => $unconfirmed];
    }
}
