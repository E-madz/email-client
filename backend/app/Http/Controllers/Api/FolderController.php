<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Exceptions\Mail\ImapAuthenticationException;
use App\Exceptions\Mail\ImapConnectionException;
use App\Http\Controllers\Concerns\HandlesImapMailExceptions;
use App\Http\Controllers\Controller;
use App\Http\Resources\FolderResource;
use App\Http\Resources\MessageResource;
use App\Models\EmailAccount;
use App\Models\Folder;
use App\Services\Mail\FolderSyncService;
use App\Services\Mail\MessageSyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FolderController extends Controller
{
    use HandlesImapMailExceptions;

    public function __construct(
        private readonly FolderSyncService $folderSyncService,
        private readonly MessageSyncService $messageSyncService,
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
}
