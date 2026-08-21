<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A logged batch of rounds fired outside SAPRF match registrations —
 * practice sessions or non-SAPRF events. Entries live under a barrel and
 * feed Barrel::round_count so the lifetime total stays a single number
 * for every consumer (rifle page, ladder analyser, etc.).
 */
class BarrelShotEntry extends Model
{
    use HasFactory;

    public const TYPE_PRACTICE = 'practice';

    public const TYPE_NON_SAPRF = 'non_saprf';

    public const TYPES = [
        self::TYPE_PRACTICE,
        self::TYPE_NON_SAPRF,
    ];

    protected $fillable = [
        'barrel_id',
        'user_id',
        'fired_on',
        'shot_count',
        'type',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'fired_on' => 'date',
            'shot_count' => 'integer',
        ];
    }

    public function barrel(): BelongsTo
    {
        return $this->belongsTo(Barrel::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function typeLabel(): string
    {
        return match ($this->type) {
            self::TYPE_PRACTICE => 'Practice',
            self::TYPE_NON_SAPRF => 'Non-SAPRF',
            default => ucfirst((string) $this->type),
        };
    }
}
