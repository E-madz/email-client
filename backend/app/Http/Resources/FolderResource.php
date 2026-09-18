<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Folder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Folder
 */
class FolderResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'type' => $this->type,
            'is_user_created' => $this->is_user_created,
            'display_name' => $this->display_name,
            'unread_count' => $this->unread_count ?? 0,
            'last_synced_at' => $this->last_synced_at?->toIso8601String(),
        ];
    }
}
