<?php

namespace App\Models;

use App\Enums\ExcoMeetingStatus;
use App\Enums\ExcoMeetingType;
use Illuminate\Database\Eloquent\Builder;
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
        'archived_at',
        'archived_by',
        'archive_reason',
    ];

    protected function casts(): array
    {
        return [
            'scheduled_at' => 'datetime',
            'type' => ExcoMeetingType::class,
            'status' => ExcoMeetingStatus::class,
            'minutes_circulated_at' => 'datetime',
            'minutes_adopted_at' => 'datetime',
            'archived_at' => 'datetime',
        ];
    }

    /**
     * Query scope for the default meetings index. Archived rows are
     * hidden by default; the archive tab passes `withArchived()`.
     */
    public function scopeNotArchived(Builder $query): Builder
    {
        return $query->whereNull('archived_at');
    }

    public function scopeOnlyArchived(Builder $query): Builder
    {
        return $query->whereNotNull('archived_at');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Whoever archived this meeting (if any). Null while active.
     */
    public function archiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'archived_by');
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

    /**
     * Proposed amendments to the circulated minutes. Populated during
     * the review window (circulated -> adopted). Preserved forever
     * regardless of resolution — accepted and rejected amendments show
     * in the printable minutes as part of the audit trail.
     */
    public function amendments(): HasMany
    {
        return $this->hasMany(ExcoMinuteAmendment::class, 'meeting_id')
            ->orderBy('status')
            ->orderBy('created_at');
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

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    /**
     * The "review window" is the period between minutes being sent out
     * to ExCo and them being formally adopted at a subsequent sitting.
     * During this window:
     *   - Any ExCo member can submit a proposed amendment.
     *   - Chair/secretary can edit the minutes text of agenda items to
     *     apply accepted amendments (other fields stay locked).
     *   - The record is not yet historical.
     *
     * Archived meetings never enter or stay in the window; adoption
     * closes it permanently.
     */
    public function isInReviewWindow(): bool
    {
        return $this->isClosed()
            && ! $this->isArchived()
            && $this->minutesAreCirculated()
            && ! $this->minutesAreAdopted();
    }
}
