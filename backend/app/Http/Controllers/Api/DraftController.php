<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Exceptions\Mail\ImapAuthenticationException;
use App\Exceptions\Mail\ImapConnectionException;
use App\Http\Controllers\Concerns\HandlesImapMailExceptions;
use App\Http\Controllers\Controller;
use App\Http\Requests\Messages\SaveDraftRequest;
use App\Http\Resources\MessageDetailResource;
use App\Models\EmailAccount;
use App\Models\Message;
use App\Services\Mail\DraftService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;

class DraftController extends Controller
{
    use HandlesImapMailExceptions;

    public function __construct(private readonly DraftService $draftService)
    {
        //
    }

    /**
     * Save a new draft — a real IMAP `APPEND` to the account's Drafts folder,
     * only mirrored locally once the server confirms it (Phase 4b).
     */
    public function store(SaveDraftRequest $request, EmailAccount $emailAccount): JsonResponse
    {
        abort_unless($emailAccount->user_id === $request->user()->id, 403);

        try {
            $draft = $this->draftService->save($emailAccount, $this->composeInput($request));
        } catch (ImapAuthenticationException|ImapConnectionException $e) {
            return $this->respondToImapFailure($emailAccount, $e);
        }

        return (new MessageDetailResource($draft->loadMissing('attachments')))->response()->setStatusCode(201);
    }

    /**
     * Update an existing draft — IMAP has no in-place edit, so this deletes
     * the old copy and appends the new content (`DraftService::update()`).
     */
    public function update(SaveDraftRequest $request, Message $message): JsonResponse
    {
        $emailAccount = $message->folder->emailAccount;
        abort_unless($emailAccount->user_id === $request->user()->id, 403);
        abort_unless($message->folder->type === 'drafts', 422, 'Only a draft message can be updated this way.');

        try {
            $draft = $this->draftService->update($message, $this->composeInput($request));
        } catch (ImapAuthenticationException|ImapConnectionException $e) {
            return $this->respondToImapFailure($emailAccount, $e);
        }

        return (new MessageDetailResource($draft->loadMissing('attachments')))->response();
    }

    /**
     * @return array{to: list<string>, cc: list<string>, bcc: list<string>, subject: string, body_html: string, attachments: list<UploadedFile>}
     */
    private function composeInput(Request $request): array
    {
        return [
            'to' => $request->input('to', []),
            'cc' => $request->input('cc', []),
            'bcc' => $request->input('bcc', []),
            'subject' => $request->input('subject', ''),
            'body_html' => $request->input('body_html', ''),
            'attachments' => $request->file('attachments', []),
        ];
    }
}
