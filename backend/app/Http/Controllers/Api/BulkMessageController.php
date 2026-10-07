<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Messages\BulkFlagsRequest;
use App\Http\Requests\Messages\BulkMessagesRequest;
use App\Http\Requests\Messages\BulkMoveMessagesRequest;
use App\Http\Resources\MessageResource;
use App\Models\Folder;
use App\Models\Message;
use App\Services\Mail\MessageDeletionService;
use App\Services\Mail\MessageFlagService;
use App\Services\Mail\MessageMoveService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;

/**
 * Multi-select actions on messages (mark read/unread, star/unstar, move,
 * delete) — the bulk counterparts of MessageController's single-message
 * endpoints. Every action here partially succeeds rather than all-or-nothing:
 * a real mail server can reject or fail on one message in a batch (already
 * gone, a folder briefly unreachable) without that being a reason to lose
 * the rest of the selection, so each service returns what succeeded and
 * what didn't rather than throwing on the first failure.
 */
class BulkMessageController extends Controller
{
    public function __construct(
        private readonly MessageFlagService $messageFlagService,
        private readonly MessageMoveService $messageMoveService,
        private readonly MessageDeletionService $messageDeletionService,
    ) {
        //
    }

    /**
     * Loads the requested messages, scoped to the authenticated user via the
     * email_account → folder → message ownership chain (consistent with
     * every single-message endpoint, CLAUDE.md §3.1). If any requested id
     * doesn't resolve to a message this user owns, the whole request is
     * rejected up front — a client should never be able to smuggle another
     * user's message id into a batch by mixing it in with their own.
     *
     * @param  list<int>  $ids
     * @return Collection<int, Message>
     */
    private function loadOwnedMessages(BulkMessagesRequest $request, array $ids): Collection
    {
        $messages = Message::query()
            ->whereIn('id', $ids)
            ->whereHas('folder.emailAccount', fn ($query) => $query->where('user_id', $request->user()->id))
            ->with('folder.emailAccount')
            ->get();

        abort_unless($messages->count() === count($ids), 403);

        return $messages;
    }

    /**
     * Bulk mark read/unread and/or star/unstar.
     */
    public function updateFlags(BulkFlagsRequest $request): JsonResponse
    {
        $messages = $this->loadOwnedMessages($request, $request->validated('message_ids'));

        $result = $this->messageFlagService->setFlagsForMany(
            $messages,
            $request->has('is_read') ? $request->boolean('is_read') : null,
            $request->has('is_flagged') ? $request->boolean('is_flagged') : null,
        );

        return response()->json([
            'updated' => MessageResource::collection($result['updated'])->resolve($request),
            'failed' => $result['failed'],
        ]);
    }

    /**
     * Bulk move to a single target folder.
     */
    public function move(BulkMoveMessagesRequest $request): JsonResponse
    {
        $messages = $this->loadOwnedMessages($request, $request->validated('message_ids'));
        $targetFolder = Folder::findOrFail($request->validated('folder_id'));
        abort_unless($targetFolder->emailAccount->user_id === $request->user()->id, 403);

        $result = $this->messageMoveService->moveManyTo($messages, $targetFolder);

        return response()->json([
            'moved' => MessageResource::collection($result['moved'])->resolve($request),
            'skipped' => $result['skipped'],
        ]);
    }

    /**
     * Bulk delete — context-aware per message's own folder, same as the
     * single-message delete endpoint (move to Trash, or permanent if already
     * there).
     */
    public function destroy(BulkMessagesRequest $request): JsonResponse
    {
        $messages = $this->loadOwnedMessages($request, $request->validated('message_ids'));

        $result = $this->messageDeletionService->deleteMany($messages);

        return response()->json([
            'trashed' => MessageResource::collection($result['trashed'])->resolve($request),
            'deleted_count' => $result['deletedCount'],
            'failed' => $result['failed'],
        ]);
    }
}
