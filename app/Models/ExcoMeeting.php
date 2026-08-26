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
        'minutes_circulated_at',
        'minutes_circulated_by',
        'minutes_adopted_at',
        'minutes_adopted_meeting_id',
    ];

    protected function casts(): array
    {
        return [
            'scheduled_at' => 'datetime',
            'type' => ExcoMeetingType::class,
            'status' => ExcoMeetingStatus::class,
            'minutes_circulated_at' => 'datetime',
            'minutes_adopted_at' => 'datetime',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Whoever pressed "Mark as circulated". Null while the minutes are
     * still an internal draft.
     */
    public function minutesCirculator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'minutes_circulated_by');
    }

    /**
     * The subsequent sitting at which these minutes were formally
     * adopted (agenda item 1 of the next meeting, typically). Null
     * until adoption is recorded.
     */
    public function adoptedAtMeeting(): BelongsTo
    {
        return $this->belongsTo(self::class, 'minutes_adopted_meeting_id');
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

    public function minutesAreCirculated(): bool
    {
        return $this->minutes_circulated_at !== null;
    }

    public function minutesAreAdopted(): bool
    {
        return $this->minutes_adopted_at !== null;
    }
}
