<?php

namespace App\Models;

use App\Enums\ExcoMeetingStatus;
use App\Enums\ExcoMeetingType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ExcoMeeting extends Model
{
    protected $fillable = [
        'title',
        'type',
        'scheduled_at',
        'location',
        'attendance_notes',
        'status',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'scheduled_at' => 'datetime',
            'type' => ExcoMeetingType::class,
            'status' => ExcoMeetingStatus::class,
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Agenda items in display order. `orderBy('id')` ties `sort_order`
     * so newly-added items land at the bottom by default.
     */
    public function agendaItems(): HasMany
    {
        return $this->hasMany(ExcoAgendaItem::class, 'meeting_id')
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    public function actions(): HasMany
    {
        return $this->hasMany(ExcoAction::class, 'meeting_id');
    }

    public function isDraft(): bool
    {
        return $this->status === ExcoMeetingStatus::Draft;
    }

    public function isClosed(): bool
    {
        return $this->status === ExcoMeetingStatus::Closed;
    }
}
