<?php

declare(strict_types=1);

namespace App\Http\Controllers\Concerns;

use App\Exceptions\Mail\SmtpAuthenticationException;
use App\Exceptions\Mail\SmtpConnectionException;
use App\Models\EmailAccount;
use Illuminate\Http\JsonResponse;

trait HandlesSmtpMailExceptions
{
    /**
     * Map a live-SMTP failure to a JSON error response, mirroring
     * HandlesImapMailExceptions. A rejected login also flags the account as
     * needing re-auth (CLAUDE.md §4.2) — IMAP and SMTP share one password on
     * a mail account, so an SMTP auth failure means the same thing an IMAP
     * one does.
     */
    protected function respondToSmtpFailure(EmailAccount $emailAccount, SmtpAuthenticationException|SmtpConnectionException $e): JsonResponse
    {
        if ($e instanceof SmtpAuthenticationException) {
            $emailAccount->markNeedsReauth();

            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['message' => $e->getMessage()], 503);
    }
}
