<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Folder;

/**
 * Whether a message can be moved from one folder to another — shared by the
 * single-message move endpoint (`MoveMessageRequest`) and the bulk move
 * endpoint (`BulkMoveMessagesRequest`/`MessageMoveService::moveManyTo()`) so
 * the rule only ever lives in one place (CLAUDE.md §8 items 66/68).
 */
final class MessageMoveEligibility
{
    /**
     * Folder types a message can never be moved *into*, since their contents
     * mean something specific that an arbitrary moved message would
     * misrepresent: `drafts` is only ever populated by the compose/save-draft
     * flow, `sent` only by a message the account itself actually sent.
     *
     * @var list<string>
     */
    private const UNMOVABLE_TARGET_TYPES = ['drafts', 'sent'];

    /**
     * `sent`/`drafts` are "outbound" (the account itself composed/sent it);
     * `inbox`/`spam` are "inbound" (mail the account received). Crossing that
     * line in either direction misrepresents the message's real origin.
     * `trash`/`archive`/`custom` are neutral staging folders this rule never
     * fires on.
     *
     * @var list<string>
     */
    private const OUTBOUND_TYPES = ['sent', 'drafts'];

    /** @var list<string> */
    private const INBOUND_TYPES = ['inbox', 'spam'];

    /**
     * Returns a user-facing reason the move isn't allowed, or null if it is.
     */
    public static function violation(Folder $source, Folder $target): ?string
    {
        if ($target->email_account_id !== $source->email_account_id) {
            return 'The target folder must belong to the same email account.';
        }

        if ($target->id === $source->id) {
            return 'The message is already in that folder.';
        }

        if ($source->type === 'drafts') {
            // A draft leaves Drafts only by being sent (which deletes it) or
            // deleted (moved to Trash via the dedicated delete endpoint) —
            // not by an arbitrary generic move.
            return 'A draft cannot be moved directly — send or delete it instead.';
        }

        if (in_array($target->type, self::UNMOVABLE_TARGET_TYPES, true)) {
            return "Messages can't be moved into the {$target->type} folder.";
        }

        if (in_array($source->type, self::OUTBOUND_TYPES, true) && in_array($target->type, self::INBOUND_TYPES, true)) {
            return "A message you sent can't be moved into {$target->type} — it was never received.";
        }

        if (in_array($source->type, self::INBOUND_TYPES, true) && in_array($target->type, self::OUTBOUND_TYPES, true)) {
            return "A received message can't be moved into {$target->type}.";
        }

        return null;
    }
}
