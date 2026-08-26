<?php

namespace App\Models;

use App\Enums\ExcoAgendaItemVisibility;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ExcoAgendaItem extends Model
{
    protected $fillable = [
        'meeting_id',
        'sort_order',
        'title',
        'briefing',
        'minutes',
        'visibility',
        'disciplinary_case_id',
    ];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'visibility' => ExcoAgendaItemVisibility::class,
        ];
    }

    public function meeting(): BelongsTo
    {
        return $this->belongsTo(ExcoMeeting::class, 'meeting_id');
    }

    public function disciplinaryCase(): BelongsTo
    {
        return $this->belongsTo(DisciplinaryCase::class, 'disciplinary_case_id');
    }

    public function actions(): HasMany
    {
        return $this->hasMany(ExcoAction::class, 'agenda_item_id');
    }

    public function isConfidential(): bool
    {
        return $this->visibility === ExcoAgendaItemVisibility::Confidential;
    }
}
