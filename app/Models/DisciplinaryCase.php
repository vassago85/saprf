<?php

namespace App\Models;

use App\Enums\DisciplinaryCaseStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DisciplinaryCase extends Model
{
    protected $table = 'disciplinary_cases';

    protected $fillable = [
        'reference',
        'subject_user_id',
        'subject_name',
        'title',
        'summary',
        'status',
        'opened_at',
        'closed_at',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'status' => DisciplinaryCaseStatus::class,
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(User::class, 'subject_user_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function notes(): HasMany
    {
        return $this->hasMany(DisciplinaryCaseNote::class, 'case_id')
            ->orderByDesc('created_at');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(DisciplinaryCaseAttachment::class, 'case_id')
            ->orderByDesc('created_at');
    }

    public function actions(): HasMany
    {
        return $this->hasMany(ExcoAction::class, 'disciplinary_case_id');
    }

    public function agendaItems(): HasMany
    {
        return $this->hasMany(ExcoAgendaItem::class, 'disciplinary_case_id');
    }

    /**
     * Human-readable subject: the platform user's name if we have one,
     * otherwise the free-text label supplied at creation. Never returns
     * null so views can dump it directly.
     */
    public function subjectLabel(): string
    {
        if ($this->subject) {
            return $this->subject->name;
        }

        return $this->subject_name ?: 'Unknown';
    }

    public function isClosed(): bool
    {
        return $this->status === DisciplinaryCaseStatus::Closed;
    }

    /**
     * Generate the next `DC-YYYY-NNN` reference. Race-free enough for a
     * small committee: we scan for the highest number this year and
     * bump by one. If two rows land at the exact same millisecond the
     * DB unique index rejects the second insert and the caller retries.
     */
    public static function nextReference(): string
    {
        $year = (int) now()->format('Y');
        $prefix = 'DC-' . $year . '-';

        $latest = static::query()
            ->where('reference', 'like', $prefix . '%')
            ->orderByDesc('id')
            ->value('reference');

        $seq = 0;
        if (is_string($latest) && preg_match('/-(\d+)$/', $latest, $m)) {
            $seq = (int) $m[1];
        }

        return $prefix . str_pad((string) ($seq + 1), 3, '0', STR_PAD_LEFT);
    }
}
