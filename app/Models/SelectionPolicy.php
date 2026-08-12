<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A versioned snapshot of the selection ruleset for a cycle. Satisfies the
 * ADM-04 requirement that every selection calculation be version-stamped
 * against the policy that was in force at the time. Multiple policies may
 * exist per cycle; the cycle's active_policy_version_id points at the one
 * currently in force.
 */
class SelectionPolicy extends Model
{
    protected $fillable = [
        'selection_cycle_id',
        'version',
        'source_path',
        'source_hash',
        'spec_json',
        'imported_by',
        'imported_at',
    ];

    protected function casts(): array
    {
        return [
            'spec_json' => 'array',
            'imported_at' => 'datetime',
        ];
    }

    public function cycle(): BelongsTo
    {
        return $this->belongsTo(SelectionCycle::class, 'selection_cycle_id');
    }

    public function importedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'imported_by');
    }
}
