<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['user_id', 'mail_server_id', 'email', 'password', 'status', 'last_verified_at'])]
#[Hidden(['password'])]
class EmailAccount extends Model
{
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'password' => 'encrypted',
            'last_verified_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<MailServer, $this>
     */
    public function mailServer(): BelongsTo
    {
        return $this->belongsTo(MailServer::class);
    }

    /**
     * @return HasMany<Folder, $this>
     */
    public function folders(): HasMany
    {
        return $this->hasMany(Folder::class);
    }

    public function markVerified(): void
    {
        $this->forceFill([
            'status' => 'active',
            'last_verified_at' => now(),
        ])->save();
    }

    public function markNeedsReauth(): void
    {
        $this->forceFill(['status' => 'needs_reauth'])->save();
    }
}
