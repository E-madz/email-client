<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Exceptions\Mail\SmtpAuthenticationException;
use App\Exceptions\Mail\SmtpConnectionException;
use App\Http\Controllers\Concerns\HandlesSmtpMailExceptions;
use App\Http\Controllers\Controller;
use App\Http\Requests\Messages\SendMessageRequest;
use App\Http\Resources\MessageDetailResource;
use App\Models\EmailAccount;
use App\Models\Message;
use App\Services\Mail\SmtpMailService;
use Illuminate\Http\JsonResponse;

class ComposeController extends Controller
{
    use HandlesSmtpMailExceptions;

    public function __construct(private readonly SmtpMailService $mailService)
    {
        //
    }

    /**
     * Compose and send a message through the account's SMTP server (Phase 4a),
     * optionally in reply to another message (Phase 4b, In-Reply-To/References
     * threading headers).
     */
    public function send(SendMessageRequest $request, EmailAccount $emailAccount): JsonResponse
    {
        abort_unless($emailAccount->user_id === $request->user()->id, 403);

        $inReplyTo = $request->validated('in_reply_to_message_id') !== null
            ? Message::find($request->validated('in_reply_to_message_id'))
            : null;

        try {
            $message = $this->mailService->send($emailAccount, [
                'to' => $request->validated('to'),
                'cc' => $request->validated('cc') ?? [],
                'bcc' => $request->validated('bcc') ?? [],
                'subject' => $request->validated('subject'),
                'body_html' => $request->validated('body_html'),
                'attachments' => $request->file('attachments', []),
            ], $inReplyTo);
        } catch (SmtpAuthenticationException|SmtpConnectionException $e) {
            return $this->respondToSmtpFailure($emailAccount, $e);
        }

        return (new MessageDetailResource($message->loadMissing('attachments')))->response()->setStatusCode(201);
    }
}
