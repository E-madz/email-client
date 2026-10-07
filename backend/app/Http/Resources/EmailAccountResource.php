<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\EmailAccount;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin EmailAccount
 */
class EmailAccountResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'email' => $this->email,
            'status' => $this->status,
            'last_verified_at' => $this->last_verified_at?->toIso8601String(),
            'mail_server' => [
                'name' => $this->mailServer->name,
                'slug' => $this->mailServer->slug,
            ],
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
