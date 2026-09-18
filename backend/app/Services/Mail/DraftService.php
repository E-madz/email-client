<?php

declare(strict_types=1);

namespace App\Services\Mail;

use App\Exceptions\Mail\ImapAuthenticationException;
use App\Exceptions\Mail\ImapConnectionException;
use App\Models\EmailAccount;
use App\Models\Message;
use Illuminate\Http\UploadedFile;

/**
 * Saves and updates Drafts — unlike a Sent-copy (§8 item 58), a draft has no
 * "it already happened over SMTP regardless" safety net, so this stays
 * remote-first with no silent local-only fallback (§3.3/§8 item 20): an IMAP
 * failure here aborts the whole operation and nothing is persisted locally.
 * Editing a draft is IMAP's standard delete-then-append (no in-place edit),
 * same as `FolderCreationService`/`FolderDeletionService`'s connect→guard→
 * disconnect pattern.
 */
final class DraftService
{
    public function __construct(
        private readonly ComposedMimeBuilder $mimeBuilder,
        private readonly ImapConnectionFactory $connectionFactory,
    ) {
        //
    }

    /**
     * @param  array{to?: list<string>, cc?: list<string>, bcc?: list<string>, subject?: string, body_html?: string, attachments?: list<UploadedFile>}  $compose
     *
     * @throws ImapAuthenticationException when the stored credentials are no longer valid
     * @throws ImapConnectionException when the server cannot be reached, no Drafts folder has been
     *                                 synced yet, or it reports an error
     */
    public function save(EmailAccount $emailAccount, array $compose): Message
    {
        $draftsFolder = $emailAccount->folders()->where('type', 'drafts')->first();

        if ($draftsFolder === null) {
            throw new ImapConnectionException('No Drafts folder found for this account yet — refresh the folder list first.');
        }

        $built = $this->mimeBuilder->build($emailAccount, $compose);

        $client = $this->connectionFactory->connectForAccount($emailAccount);

        try {
            $imapFolder = $this->connectionFactory->guard(fn () => $client->getFolderByPath($draftsFolder->name));

            if ($imapFolder === null) {
                throw new ImapConnectionException("Folder \"{$draftsFolder->name}\" not found on the mail server.");
            }

            $this->connectionFactory->guard(fn () => $imapFolder->appendMessage($built['email']->toString(), ['\Seen']));

            $appended = $this->connectionFactory->guard(
                fn () => $imapFolder->query()->whereMessageId($built['message_id'])->leaveUnread()->get()->first(),
            );
        } finally {
            $this->connectionFactory->disconnect($client);
        }

        $message = $draftsFolder->messages()->create([
            'uid' => $appended !== null ? (int) $appended->uid : (int) round(microtime(true) * 1_000_000),
            'rfc_message_id' => $built['message_id'],
            'subject' => $compose['subject'] ?? '',
            'from_name' => null,
            'from_email' => $emailAccount->email,
            'to' => $this->mimeBuilder->toRecipientList($compose['to'] ?? []),
            'cc' => $this->mimeBuilder->toRecipientList($compose['cc'] ?? []),
            'bcc' => $this->mimeBuilder->toRecipientList($compose['bcc'] ?? []),
            'date' => now(),
            'body_text' => $this->mimeBuilder->htmlToPlainText($compose['body_html'] ?? ''),
            'body_html' => $compose['body_html'] ?? '',
            'is_read' => true,
            'has_attachments' => $built['attachments'] !== [],
        ]);

        foreach ($built['attachments'] as $attachment) {
            $message->attachments()->create($attachment);
        }

        return $message;
    }

    /**
     * @param  array{to?: list<string>, cc?: list<string>, bcc?: list<string>, subject?: string, body_html?: string, attachments?: list<UploadedFile>}  $compose
     *
     * @throws ImapAuthenticationException when the stored credentials are no longer valid
     * @throws ImapConnectionException when the server cannot be reached or reports an error
     */
    public function update(Message $draft, array $compose): Message
    {
        $draftsFolder = $draft->folder;
        $emailAccount = $draftsFolder->emailAccount;

        $built = $this->mimeBuilder->build($emailAccount, $compose);

        $client = $this->connectionFactory->connectForAccount($emailAccount);

        try {
            $imapFolder = $this->connectionFactory->guard(fn () => $client->getFolderByPath($draftsFolder->name));

            if ($imapFolder === null) {
                throw new ImapConnectionException("Folder \"{$draftsFolder->name}\" not found on the mail server.");
            }

            $existing = $this->connectionFactory->guard(
                fn () => $imapFolder->query()->whereUid($draft->uid)->leaveUnread()->get()->first(),
            );
            $existing?->delete();

            $this->connectionFactory->guard(fn () => $imapFolder->appendMessage($built['email']->toString(), ['\Seen']));

            $appended = $this->connectionFactory->guard(
                fn () => $imapFolder->query()->whereMessageId($built['message_id'])->leaveUnread()->get()->first(),
            );
        } finally {
            $this->connectionFactory->disconnect($client);
        }

        $draft->update([
            'uid' => $appended !== null ? (int) $appended->uid : $draft->uid,
            'rfc_message_id' => $built['message_id'],
            'subject' => $compose['subject'] ?? '',
            'to' => $this->mimeBuilder->toRecipientList($compose['to'] ?? []),
            'cc' => $this->mimeBuilder->toRecipientList($compose['cc'] ?? []),
            'bcc' => $this->mimeBuilder->toRecipientList($compose['bcc'] ?? []),
            'body_text' => $this->mimeBuilder->htmlToPlainText($compose['body_html'] ?? ''),
            'body_html' => $compose['body_html'] ?? '',
            'has_attachments' => $built['attachments'] !== [],
        ]);

        $draft->attachments()->delete();

        foreach ($built['attachments'] as $attachment) {
            $draft->attachments()->create($attachment);
        }

        return $draft->refresh();
    }
}
