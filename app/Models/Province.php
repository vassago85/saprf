<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Province extends Model
{
    protected $fillable = [
        'name',
        'abbreviation',
    ];

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function matches(): HasMany
    {
        return $this->hasMany(MatchEvent::class);
    }

    public function committeeMembers(): HasMany
    {
        return $this->hasMany(ProvincialCommittee::class);
    }
}
