<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Notifications\ResetPasswordNotification;
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
        'club_id',
        'email_otp',
        'email_otp_expires_at',
        'email_verified_at',
        'parent_id',
        'is_managed_account',
        'managed_relationship',
        'handover_email',
        'handover_token',
        'handover_expires_at',
        'invitation_token',
        'invitation_sent_at',
        'invitation_expires_at',
        'invitation_accepted_at',
    ];

    /**
     * Relationship options for a managed (no-login) family account, keyed by the
     * value stored in `managed_relationship` => human label.
     *
     * @var array<string, string>
     */
    public const MANAGED_RELATIONSHIPS = [
        'junior' => 'Junior',
        'spouse' => 'Spouse / Partner',
        'parent' => 'Parent',
        'sibling' => 'Sibling',
        'other' => 'Other family',
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
            'invitation_sent_at' => 'datetime',
            'invitation_expires_at' => 'datetime',
            'invitation_accepted_at' => 'datetime',
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

    /**
     * Send the password reset notification with an absolute APP_URL link
     * so it can be opened on any device.
     */
    public function sendPasswordResetNotification(#[\SensitiveParameter] $token): void
    {
        $this->notify(new ResetPasswordNotification($token));
    }

    /**
     * Number of days a platform invitation link stays valid.
     */
    public const INVITATION_TTL_DAYS = 21;

    /**
     * Generate a fresh invitation token, store its hash, and return the raw
     * token to embed in the emailed link. The raw token is never persisted.
     */
    public function generateInvitationToken(): string
    {
        $raw = \Illuminate\Support\Str::random(64);

        $this->forceFill([
            'invitation_token' => hash('sha256', $raw),
            'invitation_sent_at' => now(),
            'invitation_expires_at' => now()->addDays(self::INVITATION_TTL_DAYS),
            'invitation_accepted_at' => null,
        ])->save();

        return $raw;
    }

    /**
     * Has this member been invited but not yet completed onboarding?
     */
    public function hasPendingInvitation(): bool
    {
        return $this->invitation_token !== null
            && $this->invitation_accepted_at === null
            && $this->invitation_expires_at !== null
            && $this->invitation_expires_at->isFuture();
    }

    /**
     * A member is "onboarded" once they have verified their email and no longer
     * need to change a starter password.
     */
    public function hasOnboarded(): bool
    {
        return $this->hasVerifiedEmail() && ! $this->must_change_password;
    }

    public function province(): BelongsTo
    {
        return $this->belongsTo(Province::class);
    }

    public function division(): BelongsTo
    {
        return $this->belongsTo(Division::class);
    }

    public function club(): BelongsTo
    {
        return $this->belongsTo(Club::class);
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

    /**
     * All family members this user manages (juniors, spouse, etc.).
     */
    public function managedAccounts(): HasMany
    {
        return $this->hasMany(User::class, 'parent_id');
    }

    /**
     * Backwards-compatible alias — historically all managed accounts were juniors.
     */
    public function juniors(): HasMany
    {
        return $this->hasMany(User::class, 'parent_id');
    }

    public function isManaged(): bool
    {
        return (bool) $this->is_managed_account && $this->parent_id !== null;
    }

    public function isJuniorAccount(): bool
    {
        return $this->isManaged() && $this->managed_relationship === 'junior';
    }

    /**
     * Human label for the managed relationship, e.g. "Spouse / Partner".
     */
    public function managedRelationshipLabel(): ?string
    {
        if (! $this->managed_relationship) {
            return null;
        }

        return self::MANAGED_RELATIONSHIPS[$this->managed_relationship] ?? ucfirst($this->managed_relationship);
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
