<?php

declare(strict_types=1);

namespace App\Services\Mail;

use App\Models\EmailAccount;
use App\Models\Message;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Mime\Email;

/**
 * Builds a raw RFC 822 message (a Symfony `Email` plus its stored
 * attachment metadata) from compose form input — shared by `SmtpMailService`
 * (send) and `DraftService` (save/update), so both always produce a message
 * with our own explicit `Message-ID` (needed to look the message back up by
 * header after an IMAP `APPEND`, since webklex doesn't reliably expose the
 * APPENDUID response) and the same Reply/Forward threading headers when
 * composing in reply to another message.
 */
final class ComposedMimeBuilder
{
    private const ATTACHMENTS_DISK = 'local';

    /**
     * @param  array{to?: list<string>, cc?: list<string>, bcc?: list<string>, subject?: string, body_html?: string, attachments?: list<UploadedFile>}  $compose
     * @param  list<array{filename: string, mime_type: string, storage_path: string}>  $existingAttachments  Already-stored
     *                                                                                                       attachments (e.g. a draft's previously-saved ones being kept, §8 item 62) to re-attach from disk — since
     *                                                                                                       editing a draft is delete-then-append, anything not re-included in the new raw MIME would vanish from the
     *                                                                                                       server copy even if its local DB row survives
     * @return array{email: Email, message_id: string, attachments: list<array{filename: string, mime_type: string, size_bytes: int, storage_path: string}>}
     */
    public function build(EmailAccount $emailAccount, array $compose, ?Message $inReplyTo = null, array $existingAttachments = []): array
    {
        $subject = $compose['subject'] ?? '';
        $bodyHtml = $compose['body_html'] ?? '';

        $email = (new Email)
            ->from($emailAccount->email)
            ->subject($subject)
            ->html($bodyHtml)
            ->text($this->htmlToPlainText($bodyHtml));

        $to = $compose['to'] ?? [];
        if ($to !== []) {
            $email->to(...$to);
        }

        $cc = $compose['cc'] ?? [];
        if ($cc !== []) {
            $email->cc(...$cc);
        }

        $bcc = $compose['bcc'] ?? [];
        if ($bcc !== []) {
            $email->bcc(...$bcc);
        }

        // Message-ID/In-Reply-To/References are enforced by Symfony Mime as
        // IdentificationHeader instances — addIdHeader() takes bare ids
        // (no angle brackets) and wraps them itself when rendering; our own
        // stored `rfc_message_id` convention keeps the brackets, so they're
        // stripped before being handed back to Symfony.
        $host = parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'localhost';
        $bareMessageId = Str::uuid()->toString().'@'.$host;
        $email->getHeaders()->addIdHeader('Message-ID', $bareMessageId);
        $messageId = '<'.$bareMessageId.'>';

        if ($inReplyTo?->rfc_message_id !== null) {
            $email->getHeaders()->addIdHeader('In-Reply-To', $this->stripAngleBrackets($inReplyTo->rfc_message_id));

            $referenceChain = trim(($inReplyTo->in_reply_to !== null ? $inReplyTo->in_reply_to.' ' : '').$inReplyTo->rfc_message_id);
            $references = array_map(
                $this->stripAngleBrackets(...),
                array_filter(explode(' ', $referenceChain)),
            );
            $email->getHeaders()->addIdHeader('References', $references);
        }

        foreach ($existingAttachments as $existing) {
            $email->attachFromPath(
                Storage::disk(self::ATTACHMENTS_DISK)->path($existing['storage_path']),
                $existing['filename'],
                $existing['mime_type'],
            );
        }

        $storedAttachments = [];

        foreach ($compose['attachments'] ?? [] as $file) {
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

        return [
            'email' => $email,
            'message_id' => $messageId,
            'attachments' => $storedAttachments,
        ];
    }

    /**
     * @param  list<string>  $emails
     * @return list<array{name: string, email: string}>
     */
    public function toRecipientList(array $emails): array
    {
        return array_map(fn (string $email) => ['name' => '', 'email' => $email], $emails);
    }

    public function htmlToPlainText(string $html): string
    {
        return trim(html_entity_decode(strip_tags(str_replace(['<br>', '<br/>', '<br />', '</p>'], "\n", $html))));
    }

    private function stripAngleBrackets(string $id): string
    {
        return trim($id, '<>');
    }
}
