<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A single chrono reading. Velocity is always stored in fps; if the platform
 * grows a per-user unit preference later, conversion happens at the boundary,
 * never in the service. excluded shots stay in the table so the shooter can
 * see what they dropped, but they never enter any calculation.
 */
class LadderShot extends Model
{
    use HasFactory;

    protected $fillable = [
        'ladder_step_id',
        'velocity_fps',
        'sequence',
        'excluded',
    ];

    protected function casts(): array
    {
        return [
            'velocity_fps' => 'float',
            'sequence' => 'integer',
            'excluded' => 'boolean',
        ];
    }

    public function step(): BelongsTo
    {
        return $this->belongsTo(LadderStep::class, 'ladder_step_id');
    }

    // Factory-convention alias.
    public function ladderStep(): BelongsTo
    {
        return $this->step();
    }
}
