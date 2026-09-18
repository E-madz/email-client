<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Message;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Full single-message payload (FR-3.5) — includes body_html and attachments.
 *
 * @mixin Message
 */
class MessageDetailResource extends JsonResource
{
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
            'to' => $this->to,
            'cc' => $this->cc,
            'bcc' => $this->bcc,
            'date' => $this->date->toIso8601String(),
            'body_text' => $this->body_text,
            'body_html' => $this->body_html,
            'is_read' => $this->is_read,
            'is_flagged' => $this->is_flagged,
            'attachments' => MessageAttachmentResource::collection($this->whenLoaded('attachments')),
        ];
    }
}
