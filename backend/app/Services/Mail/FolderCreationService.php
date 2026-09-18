<?php

declare(strict_types=1);

namespace App\Services\Mail;

use App\Exceptions\Mail\ImapAuthenticationException;
use App\Exceptions\Mail\ImapConnectionException;
use App\Models\EmailAccount;
use App\Models\Folder;

/**
 * Creates a real IMAP folder (top-level — no assumption about a provider's
 * namespace/delimiter convention, per CLAUDE.md §3.2) and mirrors it locally
 * only once the server confirms it exists, same remote-first discipline as
 * every other write in this app.
 */
final class FolderCreationService
{
    public function __construct(private readonly ImapConnectionFactory $connectionFactory)
    {
        //
    }

    /**
     * @throws ImapAuthenticationException when the stored credentials are no longer valid
     * @throws ImapConnectionException when the server cannot be reached or rejects the folder
     *                                 (e.g. invalid characters, already exists remotely)
     */
    public function create(EmailAccount $emailAccount, string $name): Folder
    {
        $client = $this->connectionFactory->connectForAccount($emailAccount);

        try {
            $created = $this->connectionFactory->guard(fn () => $client->createFolder($name));

            if ($created === null) {
                throw new ImapConnectionException("Could not create the folder \"{$name}\" on the mail server.");
            }
        } finally {
            $this->connectionFactory->disconnect($client);
        }

        return $emailAccount->folders()->create([
            'name' => $name,
            'type' => 'custom',
            'display_name' => $name,
            'uid_validity' => null,
            'last_uid' => 0,
        ]);
    }
}
