<?php

namespace App\Models;

use App\Notifications\AccountHandoverInvitationNotification;
use App\Notifications\ResetPasswordNotification;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
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
        'passport_number',
        'mil_le_number',
        'sa_citizen',
        'country_of_residence',
        'date_of_birth',
        'gender',
        'ethnicity',
        'previously_disadvantaged',
        'is_active',
        'must_change_password',
        'province_id',
        'division_id',
        'club_id',
        'address_line_1',
        'address_line_2',
        'address_line_3',
        'city',
        'postal_code',
        'email_verified_at',
        'parent_id',
        'is_managed_account',
        'managed_relationship',
        'public_profile_visibility',
    ];

    /**
     * Credential-bearing columns are deliberately NOT fillable: the e-mail OTP,
     * the account-handover token and the invitation token are secrets, so they
     * may only be written through the explicit helpers on this model (or a
     * `forceFill()` in the flow that owns them). That way a future
     * `fill($request->all())` can never mint or clear a login token.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'sa_id_number',
        'passport_number',
        'mil_le_number',
        'email_otp',
        'handover_token',
        'invitation_token',
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

    /**
     * SASCOC-aligned gender options, keyed by stored value => human label.
     *
     * @var array<string, string>
     */
    public const GENDER_OPTIONS = [
        'male' => 'Male',
        'female' => 'Female',
        'other' => 'Other / prefer not to say',
    ];

    /**
     * SASCOC-aligned ethnicity options, keyed by stored value => human label.
     * The taxonomy mirrors the categories used by the South African Sports
     * Confederation and Olympic Committee for demographic reporting, plus
     * a "prefer not to say" option because we can't legally compel disclosure.
     *
     * @var array<string, string>
     */
    public const ETHNICITY_OPTIONS = [
        'black_african' => 'Black African',
        'coloured' => 'Coloured',
        'indian' => 'Indian',
        'white' => 'White',
        'other' => 'Other',
        'prefer_not_to_say' => 'Prefer not to say',
    ];

    /**
     * Countries surfaced in residence dropdowns. Kept small because the only
     * functional consequence today is IPRF eligibility (SA vs. abroad).
     * "XX" is the ISO user-assigned code for "other/unknown".
     *
     * @var array<string, string>
     */
    public const COUNTRY_OPTIONS = [
        'ZA' => 'South Africa',
        'GB' => 'United Kingdom',
        'US' => 'United States',
        'AU' => 'Australia',
        'NZ' => 'New Zealand',
        'CA' => 'Canada',
        'NA' => 'Namibia',
        'BW' => 'Botswana',
        'ZW' => 'Zimbabwe',
        'AE' => 'United Arab Emirates',
        'DE' => 'Germany',
        'NL' => 'Netherlands',
        'IE' => 'Ireland',
        'CH' => 'Switzerland',
        'XX' => 'Other',
    ];

    /**
     * Ethnicity values that map to "previously disadvantaged" under
     * SASCOC / B-BBEE conventions. Used only to suggest a default on the
     * signup form — the actual boolean is stored verbatim.
     *
     * @var array<int, string>
     */
    public const PREVIOUSLY_DISADVANTAGED_ETHNICITIES = [
        'black_african',
        'coloured',
        'indian',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'email_bounced_at' => 'datetime',
            'email_complained_at' => 'datetime',
            'email_bounce_count' => 'integer',
            'email_otp_expires_at' => 'datetime',
            'handover_expires_at' => 'datetime',
            'invitation_sent_at' => 'datetime',
            'invitation_expires_at' => 'datetime',
            'invitation_accepted_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'must_change_password' => 'boolean',
            'is_managed_account' => 'boolean',
            'sa_citizen' => 'boolean',
            'previously_disadvantaged' => 'boolean',
            'date_of_birth' => 'date',
        ];
    }

    public function generateEmailOtp(): string
    {
        $otp = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        $this->forceFill([
            'email_otp' => $otp,
            'email_otp_expires_at' => now()->addMinutes(30),
        ])->save();

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

        $this->forceFill([
            'email_verified_at' => now(),
            'email_otp' => null,
            'email_otp_expires_at' => null,
        ])->save();

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
        $raw = Str::random(64);

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

    public function barrels(): HasMany
    {
        return $this->hasMany(Barrel::class);
    }

    public function ladderSessions(): HasMany
    {
        return $this->hasMany(LadderSession::class);
    }

    /**
     * Every year the user has shot for South Africa. Includes the
     * one-and-only Protea Colours-awarding appearance plus every
     * subsequent SA national-team representation. See NationalTeamAppearance.
     */
    public function nationalTeamAppearances(): HasMany
    {
        return $this->hasMany(NationalTeamAppearance::class);
    }

    /**
     * The single appearance (if any) that granted this user their Protea
     * Colours. hasOne rather than hasMany because the invariant enforced
     * by NationalTeamAppearanceController is one-per-user.
     */
    public function proteaColoursAppearance(): HasOne
    {
        return $this->hasOne(NationalTeamAppearance::class)
            ->where('awarded_colours', true);
    }

    public function hasProteaColours(): bool
    {
        if ($this->relationLoaded('proteaColoursAppearance')) {
            return $this->getRelation('proteaColoursAppearance') !== null;
        }

        return $this->nationalTeamAppearances()
            ->where('awarded_colours', true)
            ->exists();
    }

    // ──────────────────────────────────────────────────────────────────────
    // Public shooter profile visibility
    // ──────────────────────────────────────────────────────────────────────

    public const PROFILE_VISIBILITY_PUBLIC = 'public';

    public const PROFILE_VISIBILITY_MEMBERS_ONLY = 'members_only';

    public const PROFILE_VISIBILITY_HIDDEN = 'hidden';

    public const PROFILE_VISIBILITY_OPTIONS = [
        self::PROFILE_VISIBILITY_PUBLIC => 'Public — visible to anyone',
        self::PROFILE_VISIBILITY_MEMBERS_ONLY => 'Members only — hidden from guests',
        self::PROFILE_VISIBILITY_HIDDEN => 'Hidden — profile page returns 404',
    ];

    /**
     * Can $viewer see this user's public shooter profile?
     *
     * Rules:
     *   - Owner always sees their own profile.
     *   - Staff (any of User::STAFF_ROLES) always see every profile — needed
     *     for admin workflows regardless of member preference.
     *   - Otherwise the target's public_profile_visibility gates access:
     *       public        → anyone (including guests)
     *       members_only  → any authenticated user
     *       hidden        → nobody except owner/staff
     */
    public function isProfileVisibleTo(?User $viewer): bool
    {
        if ($viewer && $viewer->is($this)) {
            return true;
        }

        if ($viewer && $viewer->isStaffMember()) {
            return true;
        }

        return match ($this->public_profile_visibility ?? self::PROFILE_VISIBILITY_PUBLIC) {
            self::PROFILE_VISIBILITY_PUBLIC => true,
            self::PROFILE_VISIBILITY_MEMBERS_ONLY => $viewer !== null,
            self::PROFILE_VISIBILITY_HIDDEN => false,
            default => true,
        };
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

    /**
     * Federation staff roles. Holding any of these — or sitting on an active
     * provincial committee — means a change is made in an administrative
     * capacity rather than as an ordinary member.
     */
    public const STAFF_ROLES = ['developer', 'exco', 'chair', 'owner', 'admin', 'match_director', 'iprf_selector'];

    /**
     * Does this user act with staff/admin authority anywhere on the platform?
     */
    public function isStaffMember(): bool
    {
        return $this->hasAnyRole(self::STAFF_ROLES)
            || $this->committeePositions()->where('is_active', true)->exists();
    }

    /**
     * Federation Chair. Chairs are guaranteed to also hold `exco` — the
     * user-management form unions them on assignment — so anywhere that
     * relies on Exco-level power still works when the user is a chair.
     */
    public function isChair(): bool
    {
        return $this->hasRole('chair');
    }

    /**
     * Any member of the federation executive committee. Chair implies exco
     * by policy in UserManagementController, so this is deliberately the
     * single source of truth for "can act with Exco authority".
     */
    public function isExco(): bool
    {
        return $this->hasAnyRole(['exco', 'chair']);
    }

    /**
     * Can this user switch between the "Admin" and "Shooter" experiences?
     *
     * Anyone with a staff role can switch (they see two experiences: their
     * admin console and their own shooter view). Pure members see only the
     * shooter experience.
     */
    public function canSwitchViewMode(): bool
    {
        return $this->hasAnyRole(self::STAFF_ROLES);
    }

    /**
     * The user's current effective view mode: `admin` or `shooter`.
     *
     * Staff users default to `admin` but can flip to `shooter` via the
     * sidebar toggle; the choice is stored in the session. Non-staff users
     * are always in `shooter` mode regardless of any session value.
     */
    public function effectiveViewMode(): string
    {
        if (! $this->canSwitchViewMode()) {
            return 'shooter';
        }

        return session('view_mode') === 'shooter' ? 'shooter' : 'admin';
    }

    public function getAgeOn(Carbon $date): ?int
    {
        if (! $this->date_of_birth) {
            return null;
        }

        return (int) floor($this->date_of_birth->diffInYears($date));
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
     * A managed family account this user is allowed to act for, or null.
     */
    public function findManagedAccount(int|string|null $id): ?self
    {
        if ($id === null || $id === '') {
            return null;
        }

        return $this->managedAccounts()
            ->whereKey($id)
            ->where('is_managed_account', true)
            ->first();
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
        if ($notification instanceof AccountHandoverInvitationNotification
            && $this->handover_email) {
            return $this->handover_email;
        }

        return $this->email;
    }

    // ──────────────────────────────────────────────────────────────────────
    // Notifications
    // ──────────────────────────────────────────────────────────────────────

    /**
     * Frozen recipient rows for federation-wide announcements this user is on.
     */
    public function announcementRecipients(): HasMany
    {
        return $this->hasMany(AnnouncementRecipient::class);
    }

    public function notificationPreference(): HasOne
    {
        return $this->hasOne(NotificationPreference::class);
    }

    public function pushSubscriptions(): HasMany
    {
        return $this->hasMany(PushSubscription::class);
    }
}
