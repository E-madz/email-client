<?php

declare(strict_types=1);

namespace App\Services\Mail;

use App\Exceptions\Mail\SmtpAuthenticationException;
use App\Exceptions\Mail\SmtpConnectionException;
use App\Models\EmailAccount;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\RawMessage;
use Throwable;

/**
 * Single source of truth for turning a stored EmailAccount into a connected
 * SMTP transport, and for mapping whatever the server throws back onto our
 * own app exceptions — the SMTP-side counterpart of ImapConnectionFactory
 * (CLAUDE.md §3.1/§3.6). Every SMTP-touching service goes through here.
 */
final class SmtpTransportFactory
{
    public function __construct(private readonly SmtpTransportManager $transportManager)
    {
        //
    }

    /**
     * Symfony Mailer doesn't expose a typed auth-failure exception — every
     * SMTP-level error surfaces as the same TransportException, with the
     * server's reply code/text folded into the message. These are the SMTP
     * reply codes (RFC 5321/4954) that mean "bad credentials" rather than a
     * network/protocol problem. Same real-world skepticism CLAUDE.md §3.6
     * already asked for here: don't assume the library types this for us.
     *
     * @var list<string>
     */
    private const AUTH_FAILURE_INDICATORS = ['535', '534', '530', '5.7.8', '5.7.0', 'authentication failed'];

    /**
     * @throws SmtpAuthenticationException when the credentials are rejected by the server
     * @throws SmtpConnectionException when the server cannot be reached or an unexpected protocol error occurs
     */
    public function connectForAccount(EmailAccount $emailAccount): TransportInterface
    {
        return $this->transportManager->make($emailAccount->mailServer, $emailAccount->email, $emailAccount->password);
    }

    /**
     * Send through the given transport, mapping a transport-level failure to
     * our own exceptions. Symfony only actually talks to the server on the
     * first send() call, so auth/connection failures surface here, not when
     * the transport object is constructed.
     *
     * @throws SmtpAuthenticationException when the credentials are rejected by the server
     * @throws SmtpConnectionException when the server cannot be reached or reports an error
     */
    public function send(TransportInterface $transport, RawMessage $message): void
    {
        try {
            $transport->send($message);
        } catch (TransportExceptionInterface $e) {
            Log::error('SMTP send failed.', [
                'exception_class' => $e::class,
                'exception_message' => $e->getMessage(),
            ]);

            if ($this->isAuthenticationFailure($e)) {
                throw new SmtpAuthenticationException('The email address or password was rejected by the mail server.', previous: $e);
            }

            throw new SmtpConnectionException('Could not send the message through the mail server.', previous: $e);
        } catch (Throwable $e) {
            Log::error('Unexpected error while sending mail.', [
                'exception_class' => $e::class,
                'exception_message' => $e->getMessage(),
            ]);

            throw new SmtpConnectionException('Unexpected error while sending the message.', previous: $e);
        }
    }

    private function isAuthenticationFailure(Throwable $e): bool
    {
        $message = strtolower($e->getMessage());

        foreach (self::AUTH_FAILURE_INDICATORS as $indicator) {
            if (str_contains($message, strtolower($indicator))) {
                return true;
            }
        }

        return false;
    }
}
