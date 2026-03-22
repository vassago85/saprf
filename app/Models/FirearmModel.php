<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FirearmModel extends Model
{
    protected $fillable = ['firearm_make_id', 'name', 'is_active', 'user_submitted', 'is_approved'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'user_submitted' => 'boolean',
            'is_approved' => 'boolean',
        ];
    }

    public function make(): BelongsTo
    {
        return $this->belongsTo(FirearmMake::class, 'firearm_make_id');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true)->where('is_approved', true);
    }

    public function scopePendingApproval($query)
    {
        return $query->where('is_approved', false);
    }

    public function scopeForMake($query, int $makeId)
    {
        return $query->where('firearm_make_id', $makeId);
    }

    public function scopeSearch($query, string $term)
    {
        return $query->where('name', 'like', "%{$term}%");
    }
}
