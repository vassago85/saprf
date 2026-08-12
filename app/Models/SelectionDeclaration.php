<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * DEC-01: athlete's declaration of intention to participate. In Phase 1 the
 * admin captures this on behalf of the athlete; a self-serve form comes in a
 * later phase.
 */
class SelectionDeclaration extends Model
{
    public const STATUS_DRAFT = 'draft';
    public const STATUS_SUBMITTED = 'submitted';
    public const STATUS_WITHDRAWN = 'withdrawn';

    protected $fillable = [
        'selection_athlete_id',
        'submitted_at',
        'captured_by',
        'form_data',
        'signed_form_path',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'submitted_at' => 'datetime',
            'form_data' => 'array',
        ];
    }

    public function athlete(): BelongsTo
    {
        return $this->belongsTo(SelectionAthlete::class, 'selection_athlete_id');
    }

    public function capturedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'captured_by');
    }
}
