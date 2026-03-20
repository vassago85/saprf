<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Score extends Model
{
    protected $fillable = [
        'match_id',
        'score_import_id',
        'shooter_name',
        'user_id',
        'raw_score',
        'placement',
        'division',
        'category',
        'is_member',
        'status',
        'validation_reason',
        'match_date',
        'raw_meta',
    ];

    protected function casts(): array
    {
        return [
            'raw_score' => 'decimal:3',
            'is_member' => 'boolean',
            'match_date' => 'date',
            'raw_meta' => 'array',
        ];
    }

    public function match(): BelongsTo
    {
        return $this->belongsTo(MatchEvent::class, 'match_id');
    }

    public function import(): BelongsTo
    {
        return $this->belongsTo(ScoreImport::class, 'score_import_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function shooterLog(): HasOne
    {
        return $this->hasOne(ShooterLog::class);
    }
}
