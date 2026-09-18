<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Message;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;

/**
 * Summary shape for message lists (FR-3.3) — deliberately excludes
 * body_html; see MessageDetailResource for the full single-message payload.
 *
 * @mixin Message
 */
class MessageResource extends JsonResource
{
    private const PREVIEW_LENGTH = 150;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'folder_id' => $this->folder_id,
            'uid' => $this->uid,
            'subject' => $this->subject,
            'from' => [
                'name' => $this->from_name,
                'email' => $this->from_email,
            ],
            // Included alongside `from` (not instead of it) so the frontend
            // can decide which to display: a Sent/Drafts list shows the
            // recipient instead of the account's own address, since it's
            // otherwise redundant with "who sent this" (§8 item 66).
            'to' => $this->to ?? [],
            'date' => $this->date->toIso8601String(),
            'is_read' => $this->is_read,
            'is_flagged' => $this->is_flagged,
            'has_attachments' => $this->has_attachments,
            'preview' => Str::limit(trim((string) $this->body_text), self::PREVIEW_LENGTH),
        ];
    }
}
