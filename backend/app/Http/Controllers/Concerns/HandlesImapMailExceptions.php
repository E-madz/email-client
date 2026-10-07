<?php

declare(strict_types=1);

namespace App\Http\Controllers\Concerns;

use App\Exceptions\Mail\ImapAuthenticationException;
use App\Exceptions\Mail\ImapConnectionException;
use App\Models\EmailAccount;
use Illuminate\Http\JsonResponse;

trait HandlesImapMailExceptions
{
    /**
     * Map a live-IMAP failure to a JSON error response. An authentication
     * failure also flags the account as needing re-auth (mirrors the Phase 1
     * re-auth flow, CLAUDE.md §4.2) so the frontend can prompt for a new
     * password instead of silently retrying.
     */
    protected function respondToImapFailure(EmailAccount $emailAccount, ImapAuthenticationException|ImapConnectionException $e): JsonResponse
    {
        if ($e instanceof ImapAuthenticationException) {
            $emailAccount->markNeedsReauth();

            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['message' => $e->getMessage()], 503);
    }
}
