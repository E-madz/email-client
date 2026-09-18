<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MessageAttachment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AttachmentController extends Controller
{
    /**
     * Download an attachment's stored content. Phase 4a only stores content
     * for attachments on a message *we* composed and sent (§8) — a received
     * message's attachment has no `storage_path` yet (its content still only
     * lives on the IMAP server, per CLAUDE.md §3.3), so that case returns
     * 501 rather than pretending to serve a file that doesn't exist locally.
     */
    public function download(Request $request, MessageAttachment $attachment): StreamedResponse
    {
        $emailAccount = $attachment->message->folder->emailAccount;
        abort_unless($emailAccount->user_id === $request->user()->id, 403);

        if ($attachment->storage_path === null) {
            abort(501, 'Downloading a received message\'s attachment is not yet implemented.');
        }

        abort_unless(Storage::disk('local')->exists($attachment->storage_path), 404);

        return Storage::disk('local')->download($attachment->storage_path, $attachment->filename);
    }
}
