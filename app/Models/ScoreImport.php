<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ScoreImport extends Model
{
    protected $fillable = [
        'match_id',
        'uploaded_by',
        'source_type',
        'day',
        'original_filename',
        'import_status',
        'notes',
        'raw_payload',
    ];

    protected function casts(): array
    {
        return [
            'raw_payload' => 'array',
        ];
    }

    public function match(): BelongsTo
    {
        return $this->belongsTo(MatchEvent::class, 'match_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function scores(): HasMany
    {
        return $this->hasMany(Score::class);
    }
}
