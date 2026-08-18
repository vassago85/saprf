<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AnnouncementRecipient extends Model
{
    protected $fillable = [
        'announcement_id',
        'user_id',
        'read_at',
        'acknowledged_at',
    ];

    protected function casts(): array
    {
        return [
            'read_at' => 'datetime',
            'acknowledged_at' => 'datetime',
        ];
    }

    public function announcement(): BelongsTo
    {
        return $this->belongsTo(Announcement::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(AnnouncementDelivery::class);
    }

    public function markRead(): void
    {
        if ($this->read_at) {
            return;
        }

        $this->forceFill(['read_at' => now()])->save();
    }

    public function markAcknowledged(): void
    {
        if ($this->acknowledged_at) {
            return;
        }

        $now = now();

        $this->forceFill([
            'read_at' => $this->read_at ?? $now,
            'acknowledged_at' => $now,
        ])->save();
    }
}
