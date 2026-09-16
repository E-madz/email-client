<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\MimeHeader;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'folder_id',
    'uid',
    'rfc_message_id',
    'in_reply_to',
    'subject',
    'from_name',
    'from_email',
    'to',
    'cc',
    'bcc',
    'date',
    'body_text',
    'body_html',
    'is_read',
    'is_flagged',
    'has_attachments',
])]
class Message extends Model
{
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'uid' => 'integer',
            'to' => 'array',
            'cc' => 'array',
            'bcc' => 'array',
            'date' => 'datetime',
            'is_read' => 'boolean',
            'is_flagged' => 'boolean',
            'has_attachments' => 'boolean',
        ];
    }

    /**
     * Decoded defensively on every read, independent of sync-time decoding
     * (CLAUDE.md §8 item 33/36) — self-heals rows synced before the decoder
     * fix and any deployment where the webklex-level config fix didn't take.
     */
    protected function subject(): Attribute
    {
        return Attribute::make(get: fn (?string $value) => MimeHeader::decode($value));
    }

    protected function fromName(): Attribute
    {
        return Attribute::make(get: fn (?string $value) => MimeHeader::decode($value));
    }

    /**
     * @return BelongsTo<Folder, $this>
     */
    public function folder(): BelongsTo
    {
        return $this->belongsTo(Folder::class);
    }

    /**
     * @return HasMany<MessageAttachment, $this>
     */
    public function attachments(): HasMany
    {
        return $this->hasMany(MessageAttachment::class);
    }
}
