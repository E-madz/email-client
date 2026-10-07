<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Exceptions\Mail\ImapAuthenticationException;
use App\Exceptions\Mail\ImapConnectionException;
use App\Http\Controllers\Concerns\HandlesImapMailExceptions;
use App\Http\Controllers\Controller;
use App\Models\MessageAttachment;
use App\Services\Mail\MessageContentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

class AttachmentController extends Controller
{
    use HandlesImapMailExceptions;

    public function __construct(private readonly MessageContentService $messageContentService)
    {
        //
    }

    /**
     * Download an attachment. A composed message's attachment (§8 item 43)
     * is served straight from local storage; a received message's has no
     * `storage_path` — its content still only lives on the IMAP server (per
     * the original Phase 2 design, CLAUDE.md §3.3), so it's fetched live by
     * UID + part number, exactly like `MessageContentService::ensureBodyHtmlLoaded()`
     * fetches a body on first view.
     */
    public function download(Request $request, MessageAttachment $attachment): Response
    {
        $emailAccount = $attachment->message->folder->emailAccount;
        abort_unless($emailAccount->user_id === $request->user()->id, 403);

        if ($attachment->storage_path !== null) {
            abort_unless(Storage::disk('local')->exists($attachment->storage_path), 404);

            return Storage::disk('local')->download($attachment->storage_path, $attachment->filename);
        }

        try {
            $fetched = $this->messageContentService->fetchAttachmentContent($attachment);
        } catch (ImapAuthenticationException|ImapConnectionException $e) {
            return $this->respondToImapFailure($emailAccount, $e);
        }

        return response($fetched['content'])
            ->header('Content-Type', $fetched['mime_type'])
            ->header('Content-Disposition', 'attachment; filename="'.addslashes($attachment->filename).'"');
    }
}
