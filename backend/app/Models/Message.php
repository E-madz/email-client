<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
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
