<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Exceptions\Mail\ImapAuthenticationException;
use App\Exceptions\Mail\ImapConnectionException;
use App\Http\Controllers\Concerns\HandlesImapMailExceptions;
use App\Http\Controllers\Controller;
use App\Http\Requests\Messages\UpdateMessageFlagsRequest;
use App\Http\Resources\MessageDetailResource;
use App\Http\Resources\MessageResource;
use App\Models\Folder;
use App\Models\Message;
use App\Services\Mail\MessageContentService;
use App\Services\Mail\MessageDeletionService;
use App\Services\Mail\MessageFlagService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class MessageController extends Controller
{
    use HandlesImapMailExceptions;

    public function __construct(
        private readonly MessageContentService $messageContentService,
        private readonly MessageFlagService $messageFlagService,
        private readonly MessageDeletionService $messageDeletionService,
    ) {
        //
    }

    /**
     * Paginated, locally-stored message list for a folder (FR-3.1–FR-3.4).
     */
    public function index(Request $request, Folder $folder): AnonymousResourceCollection
    {
        abort_unless($folder->emailAccount->user_id === $request->user()->id, 403);

        $messages = $folder->messages()->orderByDesc('date')->paginate(20);

        return MessageResource::collection($messages);
    }

    /**
     * Full message detail, lazily fetching+caching body_html on first view (FR-3.5).
     */
    public function show(Request $request, Message $message): JsonResponse
    {
        $emailAccount = $message->folder->emailAccount;
        abort_unless($emailAccount->user_id === $request->user()->id, 403);

        try {
            $message = $this->messageContentService->ensureBodyHtmlLoaded($message);
        } catch (ImapAuthenticationException|ImapConnectionException $e) {
            return $this->respondToImapFailure($emailAccount, $e);
        }

        return (new MessageDetailResource($message->load('attachments')))->response();
    }

    /**
     * Update is_read/is_flagged, remote-first then local (FR-4.1–FR-4.3).
     */
    public function updateFlags(UpdateMessageFlagsRequest $request, Message $message): JsonResponse
    {
        $emailAccount = $message->folder->emailAccount;
        abort_unless($emailAccount->user_id === $request->user()->id, 403);

        try {
            $message = $this->messageFlagService->setFlags(
                $message,
                $request->has('is_read') ? $request->boolean('is_read') : null,
                $request->has('is_flagged') ? $request->boolean('is_flagged') : null,
            );
        } catch (ImapAuthenticationException|ImapConnectionException $e) {
            return $this->respondToImapFailure($emailAccount, $e);
        }

        return (new MessageResource($message))->response();
    }

    /**
     * Delete a message — context-aware, same as any real webmail client:
     * from anywhere except Trash this moves it to Trash (recoverable); from
     * inside Trash it's permanent.
     */
    public function destroy(Request $request, Message $message): JsonResponse
    {
        $emailAccount = $message->folder->emailAccount;
        abort_unless($emailAccount->user_id === $request->user()->id, 403);

        try {
            if ($message->folder->type === 'trash') {
                $this->messageDeletionService->deletePermanently($message);

                return response()->json(null, 204);
            }

            $message = $this->messageDeletionService->moveToTrash($message);
        } catch (ImapAuthenticationException|ImapConnectionException $e) {
            return $this->respondToImapFailure($emailAccount, $e);
        }

        return (new MessageResource($message))->response();
    }
}
