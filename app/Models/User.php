<?php

namespace App\Models;

use App\Models\SeasonShooterClassification;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, Notifiable, SoftDeletes;

    protected $fillable = [
        'name',
        'email',
        'password',
        'phone',
        'sa_id_number',
        'date_of_birth',
        'is_active',
        'province_id',
        'division_id',
        'email_otp',
        'email_otp_expires_at',
        'email_verified_at',
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
            'email_otp_expires_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'date_of_birth' => 'date',
        ];
    }

    public function generateEmailOtp(): string
    {
        $otp = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        $this->update([
            'email_otp' => $otp,
            'email_otp_expires_at' => now()->addMinutes(30),
        ]);

        return $otp;
    }

    public function verifyEmailOtp(string $otp): bool
    {
        if (! $this->email_otp || ! $this->email_otp_expires_at) {
            return false;
        }

        if ($this->email_otp_expires_at->isPast()) {
            return false;
        }

        if (! hash_equals($this->email_otp, $otp)) {
            return false;
        }

        $this->update([
            'email_verified_at' => now(),
            'email_otp' => null,
            'email_otp_expires_at' => null,
        ]);

        return true;
    }

    public function province(): BelongsTo
    {
        return $this->belongsTo(Province::class);
    }

    public function division(): BelongsTo
    {
        return $this->belongsTo(Division::class);
    }

    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(Category::class, 'user_category');
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

    public function ammoLoads(): HasMany
    {
        return $this->hasMany(AmmoLoad::class);
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

    public function seasonClassifications(): HasMany
    {
        return $this->hasMany(SeasonShooterClassification::class);
    }

    public function getAgeOn(Carbon $date): ?int
    {
        if (! $this->date_of_birth) {
            return null;
        }

        return $this->date_of_birth->diffInYears($date);
    }
}
