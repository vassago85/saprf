<?php

namespace App\Models;

use App\Enums\ExcoAmendmentStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A proposed change to circulated ExCo meeting minutes. See the
 * `ExcoAmendmentStatus` enum for lifecycle notes.
 */
class ExcoMinuteAmendment extends Model
{
    protected $table = 'exco_minute_amendments';

    protected $fillable = [
        'meeting_id',
        'agenda_item_id',
        'proposed_by',
        'proposed_text',
        'status',
        'resolved_by',
        'resolved_at',
        'resolution_notes',
    ];

    protected function casts(): array
    {
        return [
            'status' => ExcoAmendmentStatus::class,
            'resolved_at' => 'datetime',
        ];
    }

    public function meeting(): BelongsTo
    {
        return $this->belongsTo(ExcoMeeting::class, 'meeting_id');
    }

    public function agendaItem(): BelongsTo
    {
        return $this->belongsTo(ExcoAgendaItem::class, 'agenda_item_id');
    }

    public function proposer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'proposed_by');
    }

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    public function isPending(): bool
    {
        return $this->status === ExcoAmendmentStatus::Pending;
    }

    public function isAccepted(): bool
    {
        return $this->status === ExcoAmendmentStatus::Accepted;
    }

    public function isRejected(): bool
    {
        return $this->status === ExcoAmendmentStatus::Rejected;
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', ExcoAmendmentStatus::Pending);
    }
}
