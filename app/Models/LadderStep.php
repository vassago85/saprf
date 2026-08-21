<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A single test string on the ladder. value is charge weight in grains, or
 * seating measurement in mm, per the parent session's variable. include_in_fit
 * drives the trend line; steps toggled off produce a residual instead.
 */
class LadderStep extends Model
{
    use HasFactory;

    protected $fillable = [
        'ladder_session_id',
        'value',
        'include_in_fit',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'value' => 'float',
            'include_in_fit' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(LadderSession::class, 'ladder_session_id');
    }

    // Alias matching Laravel's factory convention (parent class → camelCase),
    // so LadderStep::factory()->for($session) works without an explicit name.
    public function ladderSession(): BelongsTo
    {
        return $this->session();
    }

    public function shots(): HasMany
    {
        return $this->hasMany(LadderShot::class)->orderBy('sequence');
    }
}
