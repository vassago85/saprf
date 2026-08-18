<?php

namespace App\Models;

use App\Enums\DeliveryChannel;
use App\Enums\DeliveryStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AnnouncementDelivery extends Model
{
    protected $fillable = [
        'announcement_recipient_id',
        'channel',
        'status',
        'error',
        'sent_at',
    ];

    protected function casts(): array
    {
        return [
            'channel' => DeliveryChannel::class,
            'status' => DeliveryStatus::class,
            'sent_at' => 'datetime',
        ];
    }

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(AnnouncementRecipient::class, 'announcement_recipient_id');
    }

    public function markSent(): void
    {
        $this->forceFill([
            'status' => DeliveryStatus::Sent,
            'sent_at' => now(),
            'error' => null,
        ])->save();
    }

    public function markFailed(string $error, DeliveryStatus $status = DeliveryStatus::Failed): void
    {
        $this->forceFill([
            'status' => $status,
            'error' => mb_substr($error, 0, 500),
        ])->save();
    }
}
