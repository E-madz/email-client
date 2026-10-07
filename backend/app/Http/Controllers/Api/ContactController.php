<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Messages\SuggestContactsRequest;
use App\Models\EmailAccount;
use App\Services\Mail\ContactSuggestionService;
use Illuminate\Http\JsonResponse;

/**
 * Recipient autocomplete for the compose form — derived from the account's
 * own message history, not a separate contacts table (see
 * ContactSuggestionService's docblock). Local-DB-only, same category as
 * MessageController's starred()/search() (no live IMAP call).
 */
class ContactController extends Controller
{
    public function __construct(private readonly ContactSuggestionService $contactSuggestionService)
    {
        //
    }

    public function suggest(SuggestContactsRequest $request, EmailAccount $emailAccount): JsonResponse
    {
        abort_unless($emailAccount->user_id === $request->user()->id, 403);

        return response()->json(
            $this->contactSuggestionService->suggest($emailAccount, $request->validated('q')),
        );
    }
}
