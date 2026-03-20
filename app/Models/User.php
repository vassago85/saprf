<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'password',
        'phone',
        'sa_id_number',
        'is_active',
        'province_id',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'sa_id_number',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
        ];
    }

    public function province(): BelongsTo
    {
        return $this->belongsTo(Province::class);
    }

    public function membership(): HasOne
    {
        return $this->hasOne(Membership::class);
    }

    public function createdMatches(): HasMany
    {
        return $this->hasMany(MatchEvent::class, 'created_by');
    }

    public function matchRegistrations(): HasMany
    {
        return $this->hasMany(MatchRegistration::class);
    }

    public function scores(): HasMany
    {
        return $this->hasMany(Score::class);
    }

    public function scoreImports(): HasMany
    {
        return $this->hasMany(ScoreImport::class, 'uploaded_by');
    }

    public function committeePositions(): HasMany
    {
        return $this->hasMany(ProvincialCommittee::class);
    }

    public function rifleConfigurations(): HasMany
    {
        return $this->hasMany(RifleConfiguration::class);
    }

    public function getAdminProvinceIds(): array
    {
        return $this->committeePositions()
            ->where('is_active', true)
            ->pluck('province_id')
            ->unique()
            ->values()
            ->toArray();
    }
}
