<?php

declare(strict_types=1);

namespace App\Services\Mail;

use App\Exceptions\Mail\ImapAuthenticationException;
use App\Exceptions\Mail\ImapConnectionException;
use App\Models\MailServer;
use Illuminate\Support\Facades\Log;
use Throwable;
use Webklex\PHPIMAP\Client;
use Webklex\PHPIMAP\ClientManager;
use Webklex\PHPIMAP\Exceptions\AuthFailedException;
use Webklex\PHPIMAP\Exceptions\ConnectionFailedException;
use Webklex\PHPIMAP\Exceptions\ImapServerErrorException;

final class EmailAuthService
{
    private const CONNECTION_TIMEOUT_SECONDS = 15;

    /**
     * IMAP response codes (RFC 5530) that indicate bad credentials rather than
     * a genuine server/protocol error. Some servers (e.g. IranServer) reject a
     * failed LOGIN with a generic ImapServerErrorException instead of the
     * library's own AuthFailedException, so the response text has to be
     * inspected to tell the two apart.
     *
     * @var list<string>
     */
    private const AUTH_FAILURE_RESPONSE_CODES = [
        'AUTHENTICATIONFAILED',
        'AUTHORIZATIONFAILED',
        'EXPIRED',
        'PRIVACYREQUIRED',
    ];

    public function __construct(private readonly ClientManager $clientManager)
    {
        //
    }

    /**
     * Perform a live IMAP handshake against the given mail server profile.
     *
     * @throws ImapAuthenticationException when the credentials are rejected by the server
     * @throws ImapConnectionException when the server cannot be reached or an unexpected protocol error occurs
     */
    public function testImapConnection(MailServer $mailServer, string $email, string $password): void
    {
        $client = $this->makeClient($mailServer, $email, $password);

        try {
            $client->connect();
        } catch (AuthFailedException $e) {
            $this->logHandshakeFailure($mailServer, $email, $e);

            throw new ImapAuthenticationException('The email address or password was rejected by the mail server.', previous: $e);
        } catch (ImapServerErrorException $e) {
            $this->logHandshakeFailure($mailServer, $email, $e);

            if ($this->isAuthenticationFailure($e)) {
                throw new ImapAuthenticationException('The email address or password was rejected by the mail server.', previous: $e);
            }

            throw new ImapConnectionException('The mail server reported an error.', previous: $e);
        } catch (ConnectionFailedException $e) {
            $this->logHandshakeFailure($mailServer, $email, $e);

            throw new ImapConnectionException('Could not reach the mail server.', previous: $e);
        } catch (Throwable $e) {
            $this->logHandshakeFailure($mailServer, $email, $e);

            throw new ImapConnectionException('Unexpected error while contacting the mail server.', previous: $e);
        } finally {
            $this->safeDisconnect($client);
        }
    }

    private function isAuthenticationFailure(ImapServerErrorException $e): bool
    {
        $message = strtoupper($e->getMessage());

        foreach (self::AUTH_FAILURE_RESPONSE_CODES as $code) {
            if (str_contains($message, $code)) {
                return true;
            }
        }

        return false;
    }

    private function logHandshakeFailure(MailServer $mailServer, string $email, Throwable $e): void
    {
        $previous = $e->getPrevious();

        Log::error('IMAP handshake failed.', [
            'mail_server' => $mailServer->slug,
            'email' => $email,
            'exception_class' => $e::class,
            'exception_message' => $e->getMessage(),
            'previous_exception_class' => $previous === null ? null : $previous::class,
            'previous_exception_message' => $previous?->getMessage(),
        ]);
    }

    private function makeClient(MailServer $mailServer, string $email, string $password): Client
    {
        return $this->clientManager->make([
            'host' => $mailServer->imap_host,
            'port' => $mailServer->imap_port,
            'protocol' => 'imap',
            'encryption' => $mailServer->imap_encryption,
            'validate_cert' => true,
            'username' => $email,
            'password' => $password,
            'authentication' => null,
            'timeout' => self::CONNECTION_TIMEOUT_SECONDS,
        ]);
    }

    private function safeDisconnect(Client $client): void
    {
        try {
            $client->disconnect();
        } catch (Throwable $e) {
            Log::warning('Failed to cleanly disconnect IMAP client after handshake.', [
                'exception' => $e->getMessage(),
            ]);
        }
    }
}
