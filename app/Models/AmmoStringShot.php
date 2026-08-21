<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One shot inside an {@see AmmoString}. Kept as its own table (rather than a
 * JSON column on the parent) so shots are individually excludable, editable,
 * and indexable for time-series queries later.
 */
class AmmoStringShot extends Model
{
    use HasFactory;

    protected $fillable = [
        'ammo_string_id',
        'sequence',
        'velocity_fps',
        'excluded',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'sequence' => 'integer',
            'velocity_fps' => 'decimal:1',
            'excluded' => 'boolean',
        ];
    }

    public function string(): BelongsTo
    {
        return $this->belongsTo(AmmoString::class, 'ammo_string_id');
    }

    /**
     * Factory-convention alias. Livewire and the factory system both prefer
     * the parent's model name as the relation name; kept alongside string()
     * because $shot->string reads more naturally in the analyser code.
     */
    public function ammoString(): BelongsTo
    {
        return $this->belongsTo(AmmoString::class, 'ammo_string_id');
    }
}
