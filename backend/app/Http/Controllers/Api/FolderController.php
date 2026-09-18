<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Exceptions\Mail\ImapAuthenticationException;
use App\Exceptions\Mail\ImapConnectionException;
use App\Http\Controllers\Concerns\HandlesImapMailExceptions;
use App\Http\Controllers\Controller;
use App\Http\Requests\Folders\StoreFolderRequest;
use App\Http\Resources\FolderResource;
use App\Http\Resources\MessageResource;
use App\Models\EmailAccount;
use App\Models\Folder;
use App\Services\Mail\FolderCreationService;
use App\Services\Mail\FolderDeletionService;
use App\Services\Mail\FolderSyncService;
use App\Services\Mail\MessageDeletionService;
use App\Services\Mail\MessageSyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FolderController extends Controller
{
    use HandlesImapMailExceptions;

    public function __construct(
        private readonly FolderSyncService $folderSyncService,
        private readonly MessageSyncService $messageSyncService,
        private readonly MessageDeletionService $messageDeletionService,
        private readonly FolderCreationService $folderCreationService,
        private readonly FolderDeletionService $folderDeletionService,
    ) {
        //
    }

    /**
     * Refresh and list an account's folders (FR-1.1–FR-1.4).
     */
    public function index(Request $request, EmailAccount $emailAccount): JsonResponse
    {
        abort_unless($emailAccount->user_id === $request->user()->id, 403);

        try {
            $folders = $this->folderSyncService->sync($emailAccount);
        } catch (ImapAuthenticationException|ImapConnectionException $e) {
            return $this->respondToImapFailure($emailAccount, $e);
        }

        return FolderResource::collection($folders)->response();
    }

    /**
     * Incrementally sync a folder's messages and return the ones just fetched (FR-2.7).
     */
    public function sync(Request $request, Folder $folder): JsonResponse
    {
        $emailAccount = $folder->emailAccount;
        abort_unless($emailAccount->user_id === $request->user()->id, 403);

        try {
            $newMessages = $this->messageSyncService->sync($folder);
        } catch (ImapAuthenticationException|ImapConnectionException $e) {
            return $this->respondToImapFailure($emailAccount, $e);
        }

        return MessageResource::collection($newMessages)->response();
    }

    /**
     * Create a new folder — a real IMAP `CREATE`, mirrored locally only once
     * the server confirms it exists.
     */
    public function store(StoreFolderRequest $request, EmailAccount $emailAccount): JsonResponse
    {
        abort_unless($emailAccount->user_id === $request->user()->id, 403);

        try {
            $folder = $this->folderCreationService->create($emailAccount, $request->validated('name'));
        } catch (ImapAuthenticationException|ImapConnectionException $e) {
            return $this->respondToImapFailure($emailAccount, $e);
        }

        return (new FolderResource($folder))->response()->setStatusCode(201);
    }

    /**
     * Permanently delete every message in the Trash folder — remote-first
     * (each message is flagged \Deleted and the folder expunged) before the
     * local rows are removed, same ordering discipline as every other
     * IMAP-backed write in this app.
     */
    public function emptyTrash(Request $request, Folder $folder): JsonResponse
    {
        abort_unless($folder->emailAccount->user_id === $request->user()->id, 403);
        abort_unless($folder->type === 'trash', 422, 'Only the Trash folder can be emptied.');

        try {
            $deleted = $this->messageDeletionService->emptyTrash($folder);
        } catch (ImapAuthenticationException|ImapConnectionException $e) {
            return $this->respondToImapFailure($folder->emailAccount, $e);
        }

        return response()->json(['deleted' => $deleted]);
    }

    /**
     * Delete a folder the user created — a real IMAP `DELETE`, only mirrored
     * locally once the server confirms it. Restricted to `custom`-type
     * folders: a system folder (Inbox/Sent/Drafts/Trash/Spam/Archive) can
     * never be deleted through this endpoint, regardless of what the client
     * sends. Deleting a non-empty folder deletes its messages too — IMAP has
     * no "move contents to Trash first" step for a mailbox deletion, so the
     * frontend is expected to warn about this before calling it.
     */
    public function destroy(Request $request, Folder $folder): JsonResponse
    {
        abort_unless($folder->emailAccount->user_id === $request->user()->id, 403);
        abort_unless($folder->type === 'custom', 422, 'Only folders you created yourself can be deleted.');

        try {
            $this->folderDeletionService->delete($folder);
        } catch (ImapAuthenticationException|ImapConnectionException $e) {
            return $this->respondToImapFailure($folder->emailAccount, $e);
        }

        return response()->json(null, 204);
    }
}
