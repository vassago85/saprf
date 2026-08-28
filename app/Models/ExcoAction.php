<?php

namespace App\Models;

use App\Enums\ExcoActionStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExcoAction extends Model
{
    protected $fillable = [
        'title',
        'details',
        'assigned_to',
        'due_on',
        'status',
        'meeting_id',
        'agenda_item_id',
        'disciplinary_case_id',
        'created_by',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'due_on' => 'date',
            'status' => ExcoActionStatus::class,
            'completed_at' => 'datetime',
        ];
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function meeting(): BelongsTo
    {
        return $this->belongsTo(ExcoMeeting::class, 'meeting_id');
    }

    public function agendaItem(): BelongsTo
    {
        return $this->belongsTo(ExcoAgendaItem::class, 'agenda_item_id');
    }

    public function disciplinaryCase(): BelongsTo
    {
        return $this->belongsTo(DisciplinaryCase::class, 'disciplinary_case_id');
    }

    public function isOpen(): bool
    {
        return $this->status === ExcoActionStatus::Open;
    }

    public function isOverdue(): bool
    {
        return $this->isOpen()
            && $this->due_on !== null
            && $this->due_on->isPast();
    }

    /**
     * Editable while it still belongs to a "live" meeting cycle:
     *   - Ad-hoc (no meeting) — always editable.
     *   - Attached to a meeting whose minutes have NOT yet been adopted.
     *
     * Once the parent meeting's minutes are adopted at a subsequent
     * sitting, the action item is part of the historical record and
     * its content (title / owner / due date / details) is frozen.
     * Completion tracking (mark done / reopen) stays available so
     * outstanding items can still be closed off.
     */
    public function isEditable(): bool
    {
        if ($this->meeting_id === null) {
            return true;
        }

        return $this->meeting !== null
            && ! $this->meeting->minutesAreAdopted();
    }
}
