<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FirearmModel extends Model
{
    protected $fillable = ['firearm_make_id', 'name', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function make(): BelongsTo
    {
        return $this->belongsTo(FirearmMake::class, 'firearm_make_id');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeForMake($query, int $makeId)
    {
        return $query->where('firearm_make_id', $makeId);
    }
}
