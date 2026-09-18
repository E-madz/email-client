<?php

declare(strict_types=1);

namespace App\Services\Mail;

use App\Models\MailServer;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mailer\Transport\Smtp\Stream\SocketStream;
use Symfony\Component\Mailer\Transport\TransportInterface;

/**
 * The only thing in this file is turning connection details into a Symfony
 * Mailer transport object. Deliberately NOT final and NOT where the
 * exception-mapping logic lives (that's SmtpTransportFactory) — same split
 * as webklex's own (non-final) ClientManager vs. our ImapConnectionFactory,
 * so a real Transport/Client can be swapped for a Mockery double in tests
 * without touching a `final` class.
 */
class SmtpTransportManager
{
    private const CONNECTION_TIMEOUT_SECONDS = 15.0;

    public function make(MailServer $mailServer, string $email, string $password): TransportInterface
    {
        $stream = new SocketStream;
        $stream->setTimeout(self::CONNECTION_TIMEOUT_SECONDS);

        // 'ssl' = implicit TLS from the first byte (port 465, this project's
        // only tested case); 'tls' = plaintext-then-STARTTLS (port 587) is
        // negotiated automatically when $tls is left null, never forced.
        $tls = $mailServer->smtp_encryption === 'ssl' ? true : null;

        $transport = new EsmtpTransport($mailServer->smtp_host, $mailServer->smtp_port, $tls, stream: $stream);
        $transport->setUsername($email);
        $transport->setPassword($password);

        return $transport;
    }
}
