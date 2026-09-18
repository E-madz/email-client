<?php

declare(strict_types=1);

namespace App\Services\Mail;

use App\Exceptions\Mail\ImapAuthenticationException;
use App\Exceptions\Mail\ImapConnectionException;
use App\Exceptions\Mail\SmtpAuthenticationException;
use App\Exceptions\Mail\SmtpConnectionException;
use App\Models\EmailAccount;
use App\Models\Folder;
use App\Models\Message;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Mime\Email;
use Throwable;

/**
 * Composes and sends a message through the account's SMTP server, then
 * records a copy in its Sent folder — a real IMAP `APPEND` first (Phase 4b),
 * falling back to a local-only row only if the append itself fails, since
 * the message has genuinely already been sent either way (§8 item 58).
 */
final class SmtpMailService
{
    public function __construct(
        private readonly SmtpTransportFactory $transportFactory,
        private readonly ImapConnectionFactory $connectionFactory,
        private readonly ComposedMimeBuilder $mimeBuilder,
    ) {
        //
    }

    /**
     * @param  array{to: list<string>, cc: list<string>, bcc: list<string>, subject: string, body_html: string, attachments: list<UploadedFile>}  $compose
     *
     * @throws SmtpAuthenticationException when the stored credentials are no longer valid
     * @throws SmtpConnectionException when the server cannot be reached or reports an error
     */
    public function send(EmailAccount $emailAccount, array $compose, ?Message $inReplyTo = null): Message
    {
        $built = $this->mimeBuilder->build($emailAccount, $compose, $inReplyTo);

        $transport = $this->transportFactory->connectForAccount($emailAccount);
        $this->transportFactory->send($transport, $built['email']);

        return $this->recordSentCopy($emailAccount, $built, $compose, $inReplyTo);
    }

    /**
     * @param  array{email: Email, message_id: string, attachments: list<array{filename: string, mime_type: string, size_bytes: int, storage_path: string}>}  $built
     * @param  array{to: list<string>, cc: list<string>, bcc: list<string>, subject: string, body_html: string}  $compose
     */
    private function recordSentCopy(EmailAccount $emailAccount, array $built, array $compose, ?Message $inReplyTo): Message
    {
        $sentFolder = $emailAccount->folders()->where('type', 'sent')->first();

        if ($sentFolder === null) {
            // The message was sent successfully regardless — not having a
            // synced Sent folder yet to file the local copy under isn't a
            // send failure, just a bookkeeping gap the next folder sync fixes.
            Log::warning('Sent successfully but no local Sent folder to record a copy in.', [
                'email_account_id' => $emailAccount->id,
            ]);

            return new Message([
                'subject' => $compose['subject'],
                'to' => $this->mimeBuilder->toRecipientList($compose['to']),
                'date' => now(),
                'is_read' => true,
            ]);
        }

        $uid = $this->appendToImapFolder($emailAccount, $sentFolder, $built['email']->toString());

        $message = $sentFolder->messages()->create([
            'uid' => $uid,
            'rfc_message_id' => $built['message_id'],
            'in_reply_to' => $inReplyTo?->rfc_message_id,
            'subject' => $compose['subject'],
            'from_name' => null,
            'from_email' => $emailAccount->email,
            'to' => $this->mimeBuilder->toRecipientList($compose['to']),
            'cc' => $this->mimeBuilder->toRecipientList($compose['cc']),
            'bcc' => $this->mimeBuilder->toRecipientList($compose['bcc']),
            'date' => now(),
            'body_text' => $this->mimeBuilder->htmlToPlainText($compose['body_html']),
            'body_html' => $compose['body_html'],
            'is_read' => true,
            'has_attachments' => $built['attachments'] !== [],
        ]);

        foreach ($built['attachments'] as $attachment) {
            $message->attachments()->create($attachment);
        }

        return $message;
    }

    /**
     * Appends the raw MIME to a real IMAP folder and returns the UID the
     * appended message will get.
     *
     * **Real-world lesson (§8 item 61):** an earlier version located the
     * appended message afterward by searching for the `Message-ID` we set
     * ourselves (`whereMessageId()->get()->first()`) — but a real IranServer
     * account showed this search isn't reliable: instead of matching only
     * our new message, it silently returned an unrelated *existing* message
     * in the folder (its `->first()` acting more like "the first message in
     * the folder" than an actual filtered match). That existing message's
     * UID then got stored as if it belonged to the new Sent/Draft copy —
     * and the next real sync of that folder would overwrite our local row
     * back to the old message's real content, since `MessageSyncService`
     * matches by `(folder_id, uid)`. The user saw this as "replying doesn't
     * add new messages, it's always just the first one."
     *
     * Fixed by never trusting a post-append search at all: `EXAMINE`'s
     * `UIDNEXT` (RFC 3501) is the UID the *next* appended message is
     * guaranteed to receive, read right before the `APPEND` — a value the
     * protocol requires the server to report accurately, unlike an optional
     * search feature. This app is single-writer per account (one user, one
     * session), so nothing else appends to the folder between the `EXAMINE`
     * and the `APPEND` in practice.
     *
     * Any failure here (auth, connection, folder missing) is logged and
     * falls back to the microsecond-timestamp synthetic UID this app has
     * used since Phase 4a (§8 item 42) — a failed append never fails the
     * send itself, since the message has already genuinely gone out over
     * SMTP.
     */
    private function appendToImapFolder(EmailAccount $emailAccount, Folder $folder, string $rawMime): int
    {
        try {
            $client = $this->connectionFactory->connectForAccount($emailAccount);

            try {
                $imapFolder = $this->connectionFactory->guard(fn () => $client->getFolderByPath($folder->name));

                if ($imapFolder === null) {
                    throw new ImapConnectionException("Folder \"{$folder->name}\" not found on the mail server.");
                }

                $status = $this->connectionFactory->guard(fn () => $imapFolder->examine());
                $expectedUid = (int) ($status['uidnext'] ?? 0);

                $this->connectionFactory->guard(fn () => $imapFolder->appendMessage($rawMime, ['\Seen']));

                if ($expectedUid > 0) {
                    return $expectedUid;
                }
            } finally {
                $this->connectionFactory->disconnect($client);
            }
        } catch (ImapAuthenticationException|ImapConnectionException|Throwable $e) {
            Log::warning('Failed to APPEND a sent copy to the real IMAP Sent folder; recording a local-only copy instead.', [
                'email_account_id' => $emailAccount->id,
                'folder' => $folder->name,
                'exception' => $e->getMessage(),
            ]);
        }

        return (int) round(microtime(true) * 1_000_000);
    }
}
