<?php

declare(strict_types=1);

namespace App\Services\Mail;

/**
 * Maps a raw IMAP folder (its SPECIAL-USE attributes per RFC 6154, falling
 * back to its name) to one of our closed set of folder types plus a Persian
 * display label. Kept provider-agnostic on purpose (CLAUDE.md §3.2) — nothing
 * here assumes IranServer's specific folder names.
 */
final class FolderTypeMapper
{
    /**
     * Deliberately the official English folder names (matching the mail
     * server's/Roundcube's own labels), not a Persian translation — the user
     * asked to see the folders' real, recognizable names rather than a
     * localized label, even though the rest of the UI is Persian/RTL (§8).
     */
    private const DISPLAY_NAMES = [
        'inbox' => 'Inbox',
        'sent' => 'Sent',
        'drafts' => 'Drafts',
        'trash' => 'Trash',
        'spam' => 'Spam',
        'archive' => 'Archive',
    ];

    /**
     * SPECIAL-USE attribute (RFC 6154) -> our folder type.
     *
     * @var array<string, string>
     */
    private const SPECIAL_USE_MAP = [
        '\Sent' => 'sent',
        '\Drafts' => 'drafts',
        '\Trash' => 'trash',
        '\Junk' => 'spam',
        '\Archive' => 'archive',
    ];

    /**
     * Name fallback used only when the server doesn't advertise SPECIAL-USE
     * attributes for a folder (common — it's an optional IMAP extension).
     *
     * @var array<string, string>
     */
    private const NAME_FALLBACK_MAP = [
        'INBOX' => 'inbox',
        'SENT' => 'sent',
        'SENT ITEMS' => 'sent',
        'SENT MESSAGES' => 'sent',
        'DRAFT' => 'drafts',
        'DRAFTS' => 'drafts',
        'TRASH' => 'trash',
        'DELETED ITEMS' => 'trash',
        'DELETED MESSAGES' => 'trash',
        'JUNK' => 'spam',
        'SPAM' => 'spam',
        'JUNK E-MAIL' => 'spam',
        'ARCHIVE' => 'archive',
        'ARCHIVES' => 'archive',
        'ALL MAIL' => 'archive',
    ];

    /**
     * @param  list<string>  $attributes  Raw IMAP folder attributes/flags (e.g. ['\HasNoChildren', '\Sent'])
     * @param  string  $delimiter  The hierarchy delimiter this server reports for this folder (e.g. `.` or `/`) —
     *                             needed to strip a parent prefix like `INBOX.` before matching the name fallback
     */
    public function map(array $attributes, string $name, string $delimiter = '/'): array
    {
        $type = $this->mapBySpecialUse($attributes) ?? $this->mapByName($name, $delimiter) ?? 'custom';

        return [
            'type' => $type,
            'display_name' => self::DISPLAY_NAMES[$type] ?? $name,
        ];
    }

    /**
     * @param  list<string>  $attributes
     */
    private function mapBySpecialUse(array $attributes): ?string
    {
        foreach ($attributes as $attribute) {
            if (isset(self::SPECIAL_USE_MAP[$attribute])) {
                return self::SPECIAL_USE_MAP[$attribute];
            }
        }

        return null;
    }

    private function mapByName(string $name, string $delimiter): ?string
    {
        $simpleName = $name;

        // IranServer (and many Courier/Dovecot mbox-style setups) uses "." as
        // the hierarchy delimiter, e.g. "INBOX.spam" — not "/" — so the
        // delimiter must come from the server's own LIST response per folder,
        // never be assumed.
        if ($delimiter !== '' && str_contains($name, $delimiter)) {
            $segments = explode($delimiter, $name);
            $simpleName = (string) end($segments);
        }

        return self::NAME_FALLBACK_MAP[strtoupper(trim($simpleName))] ?? null;
    }
}
