<?php

declare(strict_types=1);

namespace App\Services\Mail;

use App\Exceptions\Mail\ImapAuthenticationException;
use App\Exceptions\Mail\ImapConnectionException;
use App\Models\EmailAccount;
use App\Models\Message;
use Illuminate\Http\UploadedFile;
use Webklex\PHPIMAP\Folder as ImapFolder;

/**
 * Saves and updates Drafts — unlike a Sent-copy (§8 item 58), a draft has no
 * "it already happened over SMTP regardless" safety net, so this stays
 * remote-first with no silent local-only fallback (§3.3/§8 item 20): an IMAP
 * failure here aborts the whole operation and nothing is persisted locally.
 * Editing a draft is IMAP's standard delete-then-append (no in-place edit),
 * same as `FolderCreationService`/`FolderDeletionService`'s connect→guard→
 * disconnect pattern.
 *
 * The UID of a just-appended message is learned via `EXAMINE`'s `UIDNEXT`
 * (RFC 3501), read right before the `APPEND` — not by searching for the
 * `Message-ID` we set, which a real IranServer account showed can return the
 * wrong (existing) message (§8 item 61). Unlike `SmtpMailService`, a wrong
 * UID here is never acceptable even as a fallback: `update()`'s next edit
 * relies on the stored UID to find and delete the previous copy, so getting
 * it wrong would leave stale/duplicate drafts on the server.
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

            $uid = $this->appendAndGetUid($imapFolder, $built['email']->toString());
        } finally {
            $this->connectionFactory->disconnect($client);
        }

        $message = $draftsFolder->messages()->create([
            'uid' => $uid,
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
     * @param  array{to?: list<string>, cc?: list<string>, bcc?: list<string>, subject?: string, body_html?: string, attachments?: list<UploadedFile>, keep_attachment_ids?: list<int>}  $compose
     *
     * @throws ImapAuthenticationException when the stored credentials are no longer valid
     * @throws ImapConnectionException when the server cannot be reached or reports an error
     */
    public function update(Message $draft, array $compose): Message
    {
        $draftsFolder = $draft->folder;
        $emailAccount = $draftsFolder->emailAccount;

        // A previously-saved attachment being kept has to be re-attached from
        // disk into the new outgoing MIME too — editing a draft is
        // delete-then-append, so anything not included here would vanish
        // from the server copy even though its local DB row survives (§8
        // item 62).
        $keepIds = $compose['keep_attachment_ids'] ?? [];
        $keptAttachments = $draft->attachments()->whereIn('id', $keepIds)->get();

        $existingForMime = $keptAttachments
            ->filter(fn ($attachment) => $attachment->storage_path !== null)
            ->map(fn ($attachment) => [
                'filename' => $attachment->filename,
                'mime_type' => $attachment->mime_type,
                'storage_path' => $attachment->storage_path,
            ])
            ->values()
            ->all();

        $built = $this->mimeBuilder->build($emailAccount, $compose, existingAttachments: $existingForMime);

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

            $uid = $this->appendAndGetUid($imapFolder, $built['email']->toString());
        } finally {
            $this->connectionFactory->disconnect($client);
        }

        $draft->update([
            'uid' => $uid,
            'rfc_message_id' => $built['message_id'],
            'subject' => $compose['subject'] ?? '',
            'to' => $this->mimeBuilder->toRecipientList($compose['to'] ?? []),
            'cc' => $this->mimeBuilder->toRecipientList($compose['cc'] ?? []),
            'bcc' => $this->mimeBuilder->toRecipientList($compose['bcc'] ?? []),
            'body_text' => $this->mimeBuilder->htmlToPlainText($compose['body_html'] ?? ''),
            'body_html' => $compose['body_html'] ?? '',
            'has_attachments' => $keptAttachments->isNotEmpty() || $built['attachments'] !== [],
        ]);

        $draft->attachments()->whereNotIn('id', $keepIds)->delete();

        foreach ($built['attachments'] as $attachment) {
            $draft->attachments()->create($attachment);
        }

        return $draft->refresh();
    }

    /**
     * @throws ImapConnectionException when the server doesn't report a usable UIDNEXT
     */
    private function appendAndGetUid(ImapFolder $imapFolder, string $rawMime): int
    {
        $status = $this->connectionFactory->guard(fn () => $imapFolder->examine());
        $expectedUid = (int) ($status['uidnext'] ?? 0);

        if ($expectedUid <= 0) {
            throw new ImapConnectionException('The mail server did not report a UID for the new draft.');
        }

        $this->connectionFactory->guard(fn () => $imapFolder->appendMessage($rawMime, ['\Seen']));

        return $expectedUid;
    }
}
