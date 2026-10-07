<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\MimeHeader;
use Tests\TestCase;

class MimeHeaderTest extends TestCase
{
    public function test_it_decodes_a_real_world_encoded_word_subject(): void
    {
        $raw = '=?UTF-8?B?SVQtMjAyMDgg2K/Ysdiu2YjYp9iz2Kog2YfYr9iz2Ko?=';

        $this->assertSame('IT-20208 درخواست هدست', MimeHeader::decode($raw));
    }

    public function test_it_leaves_plain_text_unchanged(): void
    {
        $this->assertSame('سلام دنیا', MimeHeader::decode('سلام دنیا'));
        $this->assertSame('Hello world', MimeHeader::decode('Hello world'));
    }

    public function test_it_passes_through_null_and_empty(): void
    {
        $this->assertNull(MimeHeader::decode(null));
        $this->assertSame('', MimeHeader::decode(''));
    }
}
