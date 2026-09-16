<?php

declare(strict_types=1);

// Overrides only these two keys of webklex/laravel-imap's vendor config
// (merged in by its ServiceProvider — see CLAUDE.md §3.6 for the full
// real-world explanation). Left as 'utf-8' (the library default), a bad
// LOGIN-free real IranServer server generates encoded-word subjects like
// "=?UTF-8?B?...?=" that are never decoded, because ext-imap isn't loaded
// in this environment and HeaderDecoder::decode()'s 'utf-8' branch's
// mimeHeaderDecode() fallback returns the raw text as "decoded" without
// actually decoding it, short-circuiting before it ever reaches the working
// iconv_mime_decode()/mb_decode_mimeheader() fallbacks. Setting 'iconv' here
// routes straight to iconv_mime_decode(), which decodes correctly.
return [
    'options' => [
        'header' => 'iconv',
        'message' => 'iconv',
    ],
];
