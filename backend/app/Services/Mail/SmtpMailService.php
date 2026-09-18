<?php

declare(strict_types=1);

namespace App\Services\Mail;

use App\Exceptions\Mail\SmtpAuthenticationException;
use App\Exceptions\Mail\SmtpConnectionException;
use App\Models\EmailAccount;
use App\Models\Message;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Mime\Email;

/**
 * Composes and sends a message through the account's SMTP server, then
 * records a local copy in its Sent folder. Phase 4a scope (CLAUDE.md §8):
 * the local copy is *not* also APPENDed to the real IMAP Sent folder yet —
 * that (plus Drafts and Reply/Forward threading) is deferred to Phase 4b,
 * since it needs verifying against a real mailbox this sandbox can't reach.
 */
final class SmtpMailService
{
    private const ATTACHMENTS_DISK = 'local';

    public function __construct(private readonly SmtpTransportFactory $transportFactory)
    {
        //
    }

    /**
     * @param  array{to: list<string>, cc: list<string>, bcc: list<string>, subject: string, body_html: string, attachments: list<UploadedFile>}  $compose
     *
     * @throws SmtpAuthenticationException when the stored credentials are no longer valid
     * @throws SmtpConnectionException when the server cannot be reached or reports an error
     */
    public function send(EmailAccount $emailAccount, array $compose): Message
    {
        $email = (new Email)
            ->from($emailAccount->email)
            ->to(...$compose['to'])
            ->subject($compose['subject'])
            ->html($compose['body_html'])
            ->text($this->htmlToPlainText($compose['body_html']));

        if ($compose['cc'] !== []) {
            $email->cc(...$compose['cc']);
        }

        if ($compose['bcc'] !== []) {
            $email->bcc(...$compose['bcc']);
        }

        $storedAttachments = [];

        foreach ($compose['attachments'] as $file) {
            $storedPath = $file->store('attachments', self::ATTACHMENTS_DISK);

            $storedAttachments[] = [
                'filename' => $file->getClientOriginalName(),
                'mime_type' => $file->getMimeType() ?? 'application/octet-stream',
                'size_bytes' => $file->getSize() ?? 0,
                'storage_path' => $storedPath,
            ];

            $email->attachFromPath(
                Storage::disk(self::ATTACHMENTS_DISK)->path($storedPath),
                $file->getClientOriginalName(),
                $file->getMimeType() ?? null,
            );
        }

        $transport = $this->transportFactory->connectForAccount($emailAccount);
        $this->transportFactory->send($transport, $email);

        return $this->recordSentCopy($emailAccount, $compose, $storedAttachments);
    }

    /**
     * @param  array{to: list<string>, cc: list<string>, bcc: list<string>, subject: string, body_html: string}  $compose
     * @param  list<array{filename: string, mime_type: string, size_bytes: int, storage_path: string}>  $storedAttachments
     */
    private function recordSentCopy(EmailAccount $emailAccount, array $compose, array $storedAttachments): Message
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
                'to' => $this->toRecipientList($compose['to']),
                'date' => now(),
                'is_read' => true,
            ]);
        }

        $host = parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'localhost';

        $message = $sentFolder->messages()->create([
            // Phase 4a records the local copy only (no real IMAP APPEND yet,
            // see the class docblock), so there's no server-assigned UID to
            // use. A microsecond-resolution timestamp keeps this comfortably
            // outside the small sequential integers a real IMAP server
            // hands out, so it won't collide once Phase 4b starts APPENDing
            // real messages and syncing this folder for real.
            'uid' => (int) round(microtime(true) * 1_000_000),
            'rfc_message_id' => '<'.Str::uuid()->toString().'@'.$host.'>',
            'subject' => $compose['subject'],
            'from_name' => null,
            'from_email' => $emailAccount->email,
            'to' => $this->toRecipientList($compose['to']),
            'cc' => $this->toRecipientList($compose['cc']),
            'bcc' => $this->toRecipientList($compose['bcc']),
            'date' => now(),
            'body_text' => $this->htmlToPlainText($compose['body_html']),
            'body_html' => $compose['body_html'],
            'is_read' => true,
            'has_attachments' => $storedAttachments !== [],
        ]);

        foreach ($storedAttachments as $attachment) {
            $message->attachments()->create($attachment);
        }

        return $message;
    }

    /**
     * @param  list<string>  $emails
     * @return list<array{name: string, email: string}>
     */
    private function toRecipientList(array $emails): array
    {
        return array_map(fn (string $email) => ['name' => '', 'email' => $email], $emails);
    }

    private function htmlToPlainText(string $html): string
    {
        return trim(html_entity_decode(strip_tags(str_replace(['<br>', '<br/>', '<br />', '</p>'], "\n", $html))));
    }
}
