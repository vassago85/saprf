<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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
        'must_change_password',
        'province_id',
        'division_id',
        'email_otp',
        'email_otp_expires_at',
        'email_verified_at',
        'parent_id',
        'is_managed_account',
        'handover_email',
        'handover_token',
        'handover_expires_at',
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
            'handover_expires_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'must_change_password' => 'boolean',
            'is_managed_account' => 'boolean',
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

    public function getAgeOn(Carbon $date): ?int
    {
        if (! $this->date_of_birth) {
            return null;
        }

        return $this->date_of_birth->diffInYears($date);
    }

    // ──────────────────────────────────────────────────────────────────────
    // Family / Managed Accounts
    // ──────────────────────────────────────────────────────────────────────

    public function parent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'parent_id');
    }

    public function juniors(): HasMany
    {
        return $this->hasMany(User::class, 'parent_id');
    }

    public function isManaged(): bool
    {
        return (bool) $this->is_managed_account && $this->parent_id !== null;
    }

    public function hasPendingHandover(): bool
    {
        return $this->handover_token !== null
            && $this->handover_expires_at !== null
            && $this->handover_expires_at->isFuture();
    }

    /**
     * Route mail notifications to handover_email when sending the
     * account handover invitation (because the managed account's email
     * is a non-deliverable placeholder).
     */
    public function routeNotificationForMail($notification = null): array|string
    {
        if ($notification instanceof \App\Notifications\AccountHandoverInvitationNotification
            && $this->handover_email) {
            return $this->handover_email;
        }

        return $this->email;
    }
}
