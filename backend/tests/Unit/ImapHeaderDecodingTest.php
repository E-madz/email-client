<?php

declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;
use Webklex\PHPIMAP\Decoder\HeaderDecoder;

class ImapHeaderDecodingTest extends TestCase
{
    /**
     * Real-world regression (CLAUDE.md §3.6 / §8): with the library's default
     * 'utf-8' header decoder, a mail server's RFC 2047 encoded-word subject
     * came back completely undecoded whenever ext-imap isn't loaded, because
     * HeaderDecoder's 'utf-8' branch treats mimeHeaderDecode()'s raw-text
     * fallback as a successful decode and never falls through to
     * iconv_mime_decode()/mb_decode_mimeheader(). config/imap.php overrides
     * this app to the 'iconv' decoder, which bypasses that broken branch.
     */
    public function test_iconv_decoder_correctly_decodes_a_real_world_encoded_word_subject(): void
    {
        $decoder = new HeaderDecoder(['header' => 'iconv']);

        $raw = '=?UTF-8?B?SVQtMjAyMDgg2K/Ysdiu2YjYp9iz2Kog2YfYr9iz2Ko?=';

        $this->assertSame('IT-20208 درخواست هدست', $decoder->decode($raw));
    }

    public function test_the_library_default_utf8_decoder_fails_on_the_same_string_without_ext_imap(): void
    {
        if (extension_loaded('imap')) {
            $this->markTestSkipped('ext-imap is loaded in this environment, so the "utf-8" branch would actually decode correctly here.');
        }

        $decoder = new HeaderDecoder(['header' => 'utf-8']);

        $raw = '=?UTF-8?B?SVQtMjAyMDgg2K/Ysdiu2YjYp9iz2Kog2YfYr9iz2Ko?=';

        $this->assertNotSame('IT-20208 درخواست هدست', $decoder->decode($raw));
    }
}
