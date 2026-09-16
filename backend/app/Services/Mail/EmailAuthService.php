<?php

declare(strict_types=1);

namespace App\Services\Mail;

use App\Exceptions\Mail\ImapAuthenticationException;
use App\Exceptions\Mail\ImapConnectionException;
use App\Models\MailServer;

final class EmailAuthService
{
    public function __construct(private readonly ImapConnectionFactory $connectionFactory)
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
        $client = $this->connectionFactory->connect($mailServer, $email, $password);

        $this->connectionFactory->disconnect($client);
    }
}
