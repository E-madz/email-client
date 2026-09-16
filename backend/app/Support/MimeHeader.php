<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Decodes RFC 2047 encoded-word text (e.g. "=?UTF-8?B?...?=") on its own,
 * independent of webklex/laravel-imap's decoder pipeline/config.
 *
 * Real-world lesson (CLAUDE.md §8 item 33): relying solely on config/imap.php
 * to steer webklex's decoder has two gaps in practice — (1) a value already
 * persisted to `messages` before that config change stays undecoded forever
 * since sync is incremental and never re-fetches an already-synced message,
 * and (2) a deployment that has `config:cache`d before config/imap.php was
 * added won't pick it up until the cache is cleared/rebuilt. Decoding again
 * here, at read time via the Message model's accessors, is idempotent (a
 * string with no encoded-word survives unchanged) and self-heals both old
 * rows and any environment where the webklex-level fix didn't take.
 */
final class MimeHeader
{
    public static function decode(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return $value;
        }

        if (! str_contains($value, '=?')) {
            return $value;
        }

        if (function_exists('iconv_mime_decode')) {
            $decoded = @iconv_mime_decode($value, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8');

            if (is_string($decoded) && $decoded !== '' && ! str_contains($decoded, '=?')) {
                return $decoded;
            }
        }

        if (function_exists('mb_decode_mimeheader')) {
            $decoded = @mb_decode_mimeheader($value);

            if (is_string($decoded) && $decoded !== '') {
                return $decoded;
            }
        }

        return $value;
    }
}
