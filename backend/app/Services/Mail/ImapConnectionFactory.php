<?php

declare(strict_types=1);

namespace App\Services\Mail;

use App\Exceptions\Mail\ImapAuthenticationException;
use App\Exceptions\Mail\ImapConnectionException;
use App\Models\EmailAccount;
use App\Models\MailServer;
use Illuminate\Support\Facades\Log;
use Throwable;
use Webklex\PHPIMAP\Client;
use Webklex\PHPIMAP\ClientManager;
use Webklex\PHPIMAP\Exceptions\AuthFailedException;
use Webklex\PHPIMAP\Exceptions\ConnectionFailedException;
use Webklex\PHPIMAP\Exceptions\ImapServerErrorException;

/**
 * Single source of truth for turning an email/password (or a stored
 * EmailAccount) into a connected IMAP client, and for mapping whatever the
 * server throws back onto our own app exceptions. Every part of the app that
 * needs a live IMAP connection (auth testing, folder sync, message sync,
 * flag changes) goes through here so a real-world exception-mapping fix
 * only ever needs to be made in one place.
 */
final class ImapConnectionFactory
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
     * @throws ImapAuthenticationException when the credentials are rejected by the server
     * @throws ImapConnectionException when the server cannot be reached or an unexpected protocol error occurs
     */
    public function connectForAccount(EmailAccount $emailAccount): Client
    {
        return $this->connect($emailAccount->mailServer, $emailAccount->email, $emailAccount->password);
    }

    /**
     * @throws ImapAuthenticationException when the credentials are rejected by the server
     * @throws ImapConnectionException when the server cannot be reached or an unexpected protocol error occurs
     */
    public function connect(MailServer $mailServer, string $email, string $password): Client
    {
        $client = $this->makeClient($mailServer, $email, $password);

        try {
            $client->connect();
        } catch (AuthFailedException $e) {
            $this->logConnectionFailure($mailServer, $email, $e);
            $this->disconnect($client);

            throw new ImapAuthenticationException('The email address or password was rejected by the mail server.', previous: $e);
        } catch (ImapServerErrorException $e) {
            $this->logConnectionFailure($mailServer, $email, $e);
            $this->disconnect($client);

            if ($this->isAuthenticationFailure($e)) {
                throw new ImapAuthenticationException('The email address or password was rejected by the mail server.', previous: $e);
            }

            throw new ImapConnectionException('The mail server reported an error.', previous: $e);
        } catch (ConnectionFailedException $e) {
            $this->logConnectionFailure($mailServer, $email, $e);
            $this->disconnect($client);

            throw new ImapConnectionException('Could not reach the mail server.', previous: $e);
        } catch (Throwable $e) {
            $this->logConnectionFailure($mailServer, $email, $e);
            $this->disconnect($client);

            throw new ImapConnectionException('Unexpected error while contacting the mail server.', previous: $e);
        }

        return $client;
    }

    public function disconnect(Client $client): void
    {
        try {
            $client->disconnect();
        } catch (Throwable $e) {
            Log::warning('Failed to cleanly disconnect IMAP client.', [
                'exception' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Run any post-connection IMAP operation (listing folders, searching,
     * fetching messages, setting flags, ...), mapping an unexpected failure
     * to our own exception instead of letting a raw library exception reach
     * the controller.
     *
     * @template T
     *
     * @param  callable(): T  $operation
     * @return T
     *
     * @throws ImapConnectionException
     */
    public function guard(callable $operation): mixed
    {
        try {
            return $operation();
        } catch (Throwable $e) {
            Log::error('IMAP operation failed.', [
                'exception_class' => $e::class,
                'exception_message' => $e->getMessage(),
            ]);

            throw new ImapConnectionException('The mail server reported an error.', previous: $e);
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

    private function logConnectionFailure(MailServer $mailServer, string $email, Throwable $e): void
    {
        $previous = $e->getPrevious();

        Log::error('IMAP connection failed.', [
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
}
