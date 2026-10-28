<?php

namespace App\Models;

use App\Enums\MatchStage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class MatchEvent extends Model
{
    protected $table = 'matches';

    protected $appends = ['is_featured'];

    protected $fillable = [
        'name',
        'slug',
        'match_type',
        'series_level',
        'series',
        'season',
        'province_id',
        'venue_name',
        'venue_location',
        'city',
        'match_director',
        'match_director_contact',
        'whatsapp_invite_url',
        'description',
        'banner_path',
        'poster_path',
        'match_date',
        'match_end_date',
        'registration_open_date',
        'registration_close_date',
        'registration_close_notified_at',
        'status',
        'scores_finalized_at',
        'scores_finalized_by',
        'scores_finalized_notified_at',
        'created_by',
        'active_member_fee',
        'non_member_fee',
        'lapsed_member_fee',
        'junior_fee',
        'platform_fee_type',
        'platform_fee_value',
        'saprf_fee_type',
        'saprf_fee_value',
        'max_competitors',
        'estimated_shooters',
        'waitlist_enabled',
        'published',
        'also_counts_for_provincial',
        'everyone_counts',
        'impact_match_id',
        'impact_live_sync',
        'impact_live_synced_at',
        'impact_live_ended_at',
        'impact_live_error',
        'source_national_match_id',
        'provincial_stage_columns',
    ];

    protected function casts(): array
    {
        return [
            'match_date' => 'date',
            'match_end_date' => 'date',
            'registration_open_date' => 'date',
            'registration_close_date' => 'date',
            'registration_close_notified_at' => 'datetime',
            'scores_finalized_at' => 'datetime',
            'scores_finalized_notified_at' => 'datetime',
            'active_member_fee' => 'decimal:2',
            'non_member_fee' => 'decimal:2',
            'lapsed_member_fee' => 'decimal:2',
            'junior_fee' => 'decimal:2',
            'platform_fee_value' => 'decimal:2',
            'saprf_fee_value' => 'decimal:2',
            'max_competitors' => 'integer',
            'estimated_shooters' => 'integer',
            'waitlist_enabled' => 'boolean',
            'published' => 'boolean',
            'also_counts_for_provincial' => 'boolean',
            'everyone_counts' => 'boolean',
            'impact_match_id' => 'integer',
            'impact_live_sync' => 'boolean',
            'impact_live_synced_at' => 'datetime',
            'impact_live_ended_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (MatchEvent $match) {
            $match->slug = Str::slug($match->name).'-'.Str::random(5);
        });

        static::saving(function (MatchEvent $match) {
            $match->published = $match->status !== 'draft';
        });
    }

    /**
     * Only official WhatsApp group invite links are stored. Trailing slash is
     * allowed so directors can paste either form of the share URL.
     *
     * @return list<string>
     */
    public static function whatsappInviteUrlRules(): array
    {
        return [
            'nullable',
            'string',
            'max:255',
            'regex:/^https:\/\/chat\.whatsapp\.com\/[A-Za-z0-9_-]+\/?$/',
        ];
    }

    public function whatsappInviteUrl(): ?string
    {
        $url = $this->whatsapp_invite_url;

        return is_string($url) && $url !== '' ? $url : null;
    }

    public function hasWhatsappInvite(): bool
    {
        return $this->whatsappInviteUrl() !== null;
    }

    // ── Relationships ──

    public function province(): BelongsTo
    {
        return $this->belongsTo(Province::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function registrations(): HasMany
    {
        return $this->hasMany(MatchRegistration::class, 'match_id');
    }

    public function scoreImports(): HasMany
    {
        return $this->hasMany(ScoreImport::class, 'match_id');
    }

    public function scores(): HasMany
    {
        return $this->hasMany(Score::class, 'match_id');
    }

    /**
     * When this match is a Day-1 provincial sibling, the 2-day national it came from.
     */
    public function sourceNationalMatch(): BelongsTo
    {
        return $this->belongsTo(self::class, 'source_national_match_id');
    }

    /**
     * Day-1 provincial sibling created from this 2-day national (at most one).
     */
    public function provincialDay1Match(): HasOne
    {
        return $this->hasOne(self::class, 'source_national_match_id');
    }

    public function divisions(): BelongsToMany
    {
        return $this->belongsToMany(Division::class, 'match_division', 'match_id', 'division_id');
    }

    /**
     * Divisions a shooter may enter for this match: the divisions explicitly
     * assigned to the match, falling back to every active division when none
     * have been configured.
     *
     * @return Collection<int, Division>
     */
    public function availableDivisions(): Collection
    {
        $divisions = $this->divisions()
            ->where('is_active', true)
            ->orderBy('display_order')
            ->orderBy('name')
            ->get();

        return $divisions->isNotEmpty()
            ? $divisions
            : Division::query()->active()->ordered()->get();
    }

    public function expenses(): HasMany
    {
        return $this->hasMany(MatchExpense::class, 'match_id');
    }

    public function announcements(): HasMany
    {
        return $this->hasMany(MatchAnnouncement::class, 'match_id');
    }

    public function payouts(): HasMany
    {
        return $this->hasMany(Payout::class, 'match_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scoresFinalizer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'scores_finalized_by');
    }

    // ── Score finalisation ──

    /**
     * Whether the MD has explicitly marked the scores for this match as
     * complete (names checked, entries reconciled). Independent of
     * `status === 'completed'` — the status flag can move automatically
     * after a successful import or payout request without the MD
     * confirming the scores are actually final.
     */
    public function scoresAreFinalized(): bool
    {
        return $this->scores_finalized_at !== null;
    }

    // ── Computed Accessors ──

    /**
     * Display name for the match director: the scraped/entered MD name when
     * present, otherwise the account that owns the match.
     */
    protected function directorName(): Attribute
    {
        return Attribute::get(fn () => $this->match_director ?: $this->creator?->name);
    }

    protected function registrationStatus(): Attribute
    {
        return Attribute::get(function () {
            if ($this->status === 'cancelled') {
                return 'cancelled';
            }
            if ($this->status === 'completed') {
                return 'closed';
            }
            if (! $this->hasRequiredSetup()) {
                return 'setup_incomplete';
            }
            if ($this->registration_close_date && $this->registration_close_date->isPast()) {
                return 'closed';
            }
            if ($this->isFull()) {
                return $this->waitlist_enabled ? 'waitlist' : 'full';
            }
            if ($this->status === 'open' && $this->published) {
                return 'open';
            }

            return 'closed';
        });
    }

    protected function availableSlots(): Attribute
    {
        return Attribute::get(function () {
            if (! $this->max_competitors) {
                return null;
            }

            $confirmed = $this->registrations()
                ->whereIn('registration_status', ['confirmed', 'pending'])
                ->count();

            return max(0, $this->max_competitors - $confirmed);
        });
    }

    protected function formattedDate(): Attribute
    {
        return Attribute::get(function () {
            if (! $this->match_date) {
                return '';
            }

            if ($this->match_end_date && ! $this->match_end_date->isSameDay($this->match_date)) {
                if ($this->match_date->month === $this->match_end_date->month) {
                    return $this->match_date->format('j').'–'.$this->match_end_date->format('j M Y');
                }

                return $this->match_date->format('j M').' – '.$this->match_end_date->format('j M Y');
            }

            return $this->match_date->format('D, j M Y');
        });
    }

    public function isMultiDay(): bool
    {
        return $this->match_end_date && ! $this->match_end_date->isSameDay($this->match_date);
    }

    public function isTwoDayNational(): bool
    {
        return $this->isMultiDay() && $this->series_level === 'national';
    }

    /**
     * Find or create the provincial Day-1 sibling used so day-1 totals from a
     * 2-day national feed shooter logs / provincial standings without dual-counting
     * the national itself.
     */
    public function findOrCreateProvincialDay1Sibling(): self
    {
        if (! $this->isTwoDayNational()) {
            throw new \InvalidArgumentException(
                'Provincial Day-1 siblings can only be created from a 2-day national match.'
            );
        }

        $existing = $this->provincialDay1Match;
        if ($existing) {
            return $existing;
        }

        return self::create([
            'name' => $this->name.' — Provincial (Day 1)',
            'match_type' => $this->match_type,
            'series' => $this->series,
            'series_level' => 'provincial',
            'season' => $this->season,
            'province_id' => $this->province_id,
            'venue_name' => $this->venue_name,
            'venue_location' => $this->venue_location,
            'city' => $this->city,
            'match_director' => $this->match_director,
            'match_director_contact' => $this->match_director_contact,
            'match_date' => $this->match_date,
            'match_end_date' => null,
            'status' => 'completed',
            'created_by' => $this->created_by,
            'published' => true,
            // Membership check applies as normal. Historically this defaulted
            // to `true` while the old SAPRF site was still in the migration
            // window — day-1 CSVs arrived with a mix of expired / never-
            // registered members whose current-platform membership rows
            // hadn't been backfilled yet, so we blanket-accepted every
            // shooter to avoid demoting scores whose "lapsed" status was
            // actually just a data-migration gap. That window is closed:
            // the old site is retired and everyone with a real membership
            // has a live record here. Flip an individual match back on via
            // the match edit page if a specific import genuinely needs it.
            'everyone_counts' => false,
            'also_counts_for_provincial' => false,
            'source_national_match_id' => $this->id,
            'description' => 'Day-1 provincial results from the linked 2-day national. Counts toward provincial standings and shooter logs.',
            'active_member_fee' => $this->active_member_fee ?? 0,
            'non_member_fee' => $this->non_member_fee ?? 0,
            'lapsed_member_fee' => $this->lapsed_member_fee ?? 0,
            'junior_fee' => $this->junior_fee,
        ]);
    }

    protected function locationDisplay(): Attribute
    {
        return Attribute::get(function () {
            $cityPart = $this->city ?: $this->venue_location;
            $provinceName = $this->province?->name;

            if (! $cityPart && ! $provinceName) {
                return '';
            }

            if ($provinceName && $cityPart && str_contains($cityPart, $provinceName)) {
                return $cityPart;
            }

            return implode(', ', array_filter([$cityPart, $provinceName]));
        });
    }

    // ── Helper Methods ──

    public function isFull(): bool
    {
        if (! $this->max_competitors) {
            return false;
        }

        return $this->registrations()
            ->whereIn('registration_status', ['confirmed', 'pending'])
            ->count() >= $this->max_competitors;
    }

    /**
     * A match only accepts sign-ups once it is fully set up: a named match
     * director and a configured entry fee. A NULL fee means "not set yet"
     * (stays closed); R0 is a valid free match and passes this check.
     */
    public function hasRequiredSetup(): bool
    {
        return filled($this->match_director) && $this->active_member_fee !== null;
    }

    public function isRegistrationOpen(): bool
    {
        return $this->registration_status === 'open';
    }

    public function isWaitlistOpen(): bool
    {
        return $this->registration_status === 'waitlist';
    }

    public function confirmedRegistrationCount(): int
    {
        return $this->registrations()
            ->whereIn('registration_status', ['confirmed', 'pending'])
            ->count();
    }

    public function bannerUrl(): ?string
    {
        if (! $this->banner_path) {
            return null;
        }

        return asset('storage/'.$this->banner_path);
    }

    /**
     * Full vertical flyer for the match page. Prefer this over the wide
     * listing banner when presenting the complete promotional graphic.
     */
    public function posterUrl(): ?string
    {
        if (! $this->poster_path) {
            return null;
        }

        return asset('storage/'.$this->poster_path);
    }

    /**
     * Image used on public event cards / wide heroes. Falls back to the
     * poster only when no dedicated listing crop was uploaded.
     */
    public function listingImageUrl(): ?string
    {
        return $this->bannerUrl() ?? $this->posterUrl();
    }

    /**
     * Short label for a subtle strip on the listing image — e.g. "2-Day Championship".
     * Returns null for ordinary club matches so the overlay stays rare.
     */
    public function listingCaption(): ?string
    {
        $level = match ($this->series_level) {
            'final' => 'Championship',
            'national' => 'National',
            'international' => 'International',
            default => null,
        };

        if ($level === null) {
            return null;
        }

        return $this->isMultiDay() ? "2-Day {$level}" : $level;
    }

    public function userRegistration(?User $user): ?MatchRegistration
    {
        if (! $user) {
            return null;
        }

        return $this->registrations()
            ->where('user_id', $user->id)
            ->whereNotIn('registration_status', ['cancelled'])
            ->first();
    }

    // ── Query Scopes ──

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('published', true)->where('status', '!=', 'draft');
    }

    public function scopeUpcoming(Builder $query): Builder
    {
        return $query->where(function (Builder $q) {
            $q->where('match_date', '>=', now()->startOfDay())
                ->orWhere(function (Builder $q2) {
                    $q2->whereNotNull('match_end_date')
                        ->where('match_end_date', '>=', now()->startOfDay());
                });
        })->whereIn('status', ['open', 'closed', 'draft', 'cancelled']);
    }

    /**
     * Matches that fall on today — the start date is on or before today, and
     * the effective end (match_end_date, else match_date) is still today or
     * later. Used on the homepage "Live now" block. Status open or closed, so
     * a 2-day national whose registration has already closed still shows.
     */
    public function scopeLive(Builder $query): Builder
    {
        $today = now()->startOfDay()->toDateString();

        // whereDate, not a raw compare: SQLite stores date casts as
        // "Y-m-d H:i:s", so match_date <= '2026-10-28' misses a match on today.
        return $query->whereIn('status', ['open', 'closed'])
            ->whereDate('match_date', '<=', $today)
            ->where(function (Builder $q) use ($today) {
                $q->where(function (Builder $q2) use ($today) {
                    $q2->whereNull('match_end_date')
                        ->whereDate('match_date', $today);
                })->orWhere(function (Builder $q2) use ($today) {
                    $q2->whereNotNull('match_end_date')
                        ->whereDate('match_end_date', '>=', $today);
                });
            });
    }

    /**
     * Matches the scheduler should pull from Impact: on today, sync switched
     * on, an Impact id saved, and Impact has not already marked it finished.
     */
    public function scopeDueForImpactLiveSync(Builder $query): Builder
    {
        $today = now()->startOfDay()->toDateString();

        // whereDate, not a raw equality: SQLite stores date casts as
        // "Y-m-d H:i:s", so match_date = '2026-10-28' would miss them.
        // MariaDB DATE columns compare the same way through whereDate.
        return $query->whereIn('status', ['open', 'closed'])
            ->whereDate('match_date', '<=', $today)
            ->where(function (Builder $q) use ($today) {
                $q->where(function (Builder $q2) use ($today) {
                    $q2->whereNull('match_end_date')
                        ->whereDate('match_date', $today);
                })->orWhere(function (Builder $q2) use ($today) {
                    $q2->whereNotNull('match_end_date')
                        ->whereDate('match_end_date', '>=', $today);
                });
            })
            ->where('impact_live_sync', true)
            ->whereNotNull('impact_match_id')
            ->whereNull('impact_live_ended_at');
    }

    public function isDueForImpactLiveSync(): bool
    {
        if (! $this->impact_live_sync || ! $this->impact_match_id || $this->impact_live_ended_at !== null) {
            return false;
        }

        if (! in_array($this->status, ['open', 'closed'], true) || $this->match_date === null) {
            return false;
        }

        $today = now()->startOfDay();
        if ($this->match_date->gt($today)) {
            return false;
        }

        $end = $this->match_end_date ?? $this->match_date;

        if ($this->match_end_date === null) {
            return $this->match_date->isSameDay($today);
        }

        return $end->gte($today);
    }

    public function scopePast(Builder $query): Builder
    {
        // Cancelled matches never belong under Results — they can still appear
        // on Upcoming while the date is in the future, then drop off entirely.
        return $query->where('status', '!=', 'cancelled')
            ->where(function (Builder $q) {
                $q->where(function (Builder $q2) {
                    $q2->whereNull('match_end_date')
                        ->where('match_date', '<', now()->startOfDay());
                })->orWhere(function (Builder $q2) {
                    $q2->whereNotNull('match_end_date')
                        ->where('match_end_date', '<', now()->startOfDay());
                })->orWhere('status', 'completed');
            });
    }

    public function scopeFeatured(Builder $query): Builder
    {
        $ids = static::nextUpcomingNationalIds();

        return $query->whereIn('id', $ids);
    }

    protected static ?array $featuredIdsCache = null;

    /**
     * Returns the IDs of the next upcoming national for each series (PRS + PR22).
     * Cached per request to avoid repeated queries when rendering card lists.
     */
    public static function nextUpcomingNationalIds(): array
    {
        if (static::$featuredIdsCache !== null) {
            return static::$featuredIdsCache;
        }

        $ids = [];

        foreach (['PRS', 'PR22'] as $series) {
            $match = static::query()
                ->published()
                ->where('match_type', $series)
                ->whereIn('series_level', ['national', 'final'])
                ->where(function (Builder $q) {
                    $q->where('match_date', '>=', now()->startOfDay())
                        ->orWhere(function (Builder $q2) {
                            $q2->whereNotNull('match_end_date')
                                ->where('match_end_date', '>=', now()->startOfDay());
                        });
                })
                ->whereNotIn('status', ['completed', 'cancelled'])
                ->orderBy('match_date')
                ->first(['id']);

            if ($match) {
                $ids[] = $match->id;
            }
        }

        return static::$featuredIdsCache = $ids;
    }

    public static function clearFeaturedCache(): void
    {
        static::$featuredIdsCache = null;
    }

    public function getIsFeaturedAttribute(): bool
    {
        return in_array($this->id, static::nextUpcomingNationalIds());
    }

    public function scopeForDiscipline(Builder $query, ?string $discipline): Builder
    {
        return $discipline ? $query->where('match_type', $discipline) : $query;
    }

    public function scopeForLevel(Builder $query, ?string $level): Builder
    {
        return $level ? $query->where('series_level', $level) : $query;
    }

    public function scopeForProvince(Builder $query, ?int $provinceId): Builder
    {
        return $provinceId ? $query->where('province_id', $provinceId) : $query;
    }

    public function scopeForStatus(Builder $query, ?string $status): Builder
    {
        if (! $status) {
            return $query;
        }

        return match ($status) {
            'open' => $query->where('status', 'open'),
            'closed' => $query->where('status', 'closed'),
            'completed' => $query->where('status', 'completed'),
            'cancelled' => $query->where('status', 'cancelled'),
            'upcoming' => $query->upcoming(),
            'past' => $query->past(),
            default => $query,
        };
    }

    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        if (! $term) {
            return $query;
        }

        return $query->where(function (Builder $q) use ($term) {
            $q->where('name', 'like', "%{$term}%")
                ->orWhere('venue_name', 'like', "%{$term}%")
                ->orWhere('venue_location', 'like', "%{$term}%")
                ->orWhere('city', 'like', "%{$term}%");
        });
    }

    // ── Workspace stage + next-action ──

    /**
     * Current lifecycle stage of the match, derived from existing columns
     * (no new persisted status). Signals used:
     *
     *   - Draft:          status === 'draft' or hasRequiredSetup() === false
     *   - EntriesOpen:    published and (status === 'open' or registration
     *                     not yet closed) and no score import exists
     *   - EntriesClosed:  entries closed by date/status, no score import yet
     *   - Scores:         at least one score import on the match
     *   - Results:        a non-pending score import exists AND the match
     *                     has scores (results visible to public)
     *   - Payout:         match status === 'completed' or an MD payout row
     *                     exists for this match
     */
    public function currentStage(): MatchStage
    {
        if ($this->status === 'completed' || $this->payouts()->where('payee_type', 'match_director')->exists()) {
            return MatchStage::Payout;
        }

        $hasAnyScore = $this->scores()->exists();
        $hasImport = $this->scoreImports()->exists();

        if ($hasAnyScore) {
            return MatchStage::Results;
        }

        if ($hasImport) {
            return MatchStage::Scores;
        }

        if ($this->status === 'draft' || ! $this->hasRequiredSetup()) {
            return MatchStage::Draft;
        }

        if ($this->status === 'cancelled') {
            // Cancelled matches never reach payout; treat as closed for
            // workspace purposes. The MatchStage enum does not model
            // cancellation separately — status badge in the header
            // conveys that detail.
            return MatchStage::EntriesClosed;
        }

        $closed = $this->status === 'closed'
            || ($this->registration_close_date && $this->registration_close_date->isPast())
            || ($this->match_date && $this->match_date->isPast());

        if ($closed) {
            return MatchStage::EntriesClosed;
        }

        return MatchStage::EntriesOpen;
    }

    /**
     * Describes the current stage, the full stepper, and the single
     * recommended next action for the match workspace chrome.
     *
     * @return array{
     *     stage: MatchStage,
     *     steps: list<array{stage: MatchStage, label: string, detail: ?string, done: bool, current: bool}>,
     *     next: ?array{label: string, detail: ?string, route: ?string, params: array<string, mixed>, variant: string}
     * }
     */
    public function stageContext(): array
    {
        $current = $this->currentStage();
        $currentOrder = $current->order();

        $steps = [];
        foreach (MatchStage::orderedSteps() as $stage) {
            $steps[] = [
                'stage' => $stage,
                'label' => $stage->label(),
                'detail' => $this->stageDetailLine($stage),
                'done' => $stage->order() < $currentOrder,
                'current' => $stage === $current,
            ];
        }

        return [
            'stage' => $current,
            'steps' => $steps,
            'next' => $this->nextActionFor($current),
        ];
    }

    /**
     * Short caption shown under each step name in the workspace stepper.
     * Intentionally terse — the step label does the talking.
     */
    private function stageDetailLine(MatchStage $stage): ?string
    {
        return match ($stage) {
            MatchStage::Draft => $this->hasRequiredSetup() ? 'Ready to publish' : 'Set up',
            MatchStage::EntriesOpen => $this->registration_close_date
                ? 'Closes '.$this->registration_close_date->format('j M')
                : 'Taking entries',
            MatchStage::EntriesClosed => $this->registration_close_date
                ? 'Closed '.$this->registration_close_date->format('j M')
                : 'Closed',
            MatchStage::Scores => 'Imported '.optional($this->scoreImports()->latest('created_at')->value('created_at'))->format('j M'),
            MatchStage::Results => 'Published',
            MatchStage::Payout => $this->payoutStatusLine(),
        };
    }

    private function payoutStatusLine(): string
    {
        $payout = $this->payouts()->where('payee_type', 'match_director')->latest('id')->first();
        if (! $payout) {
            return 'Awaiting request';
        }

        return match ($payout->status) {
            'paid' => 'Paid R '.number_format((float) $payout->paid_amount, 2),
            'partial' => 'Partially paid',
            default => 'Pending R '.number_format((float) $payout->net_amount, 2),
        };
    }

    /**
     * Single-primary-action banner for the current stage. Returns null when
     * nothing is actionable (e.g. the payout has been paid and no further MD
     * step exists).
     */
    private function nextActionFor(MatchStage $stage): ?array
    {
        $payout = $this->payouts()->where('payee_type', 'match_director')->latest('id')->first();

        return match ($stage) {
            MatchStage::Draft => $this->hasRequiredSetup()
                ? [
                    'label' => 'Publish and open entries',
                    'detail' => 'Fees are set. Flip the match status to Open from Settings.',
                    'route' => 'matches.settings',
                    'params' => ['match' => $this->id],
                    'variant' => 'primary',
                ]
                : [
                    'label' => 'Finish match setup',
                    'detail' => 'Add a match director and entry fee before entries can open.',
                    'route' => 'matches.settings',
                    'params' => ['match' => $this->id],
                    'variant' => 'primary',
                ],
            MatchStage::EntriesOpen => [
                'label' => 'Close entries',
                'detail' => 'Switch status to Closed when registrations are done. You can still edit entries from the Entries tab.',
                'route' => 'matches.settings',
                'params' => ['match' => $this->id],
                'variant' => 'primary',
            ],
            MatchStage::EntriesClosed => [
                'label' => 'Export entries for Impact',
                'detail' => 'Download the CSV, score the match in Impact, then import results.',
                'route' => 'matches.export-impact-scoring',
                'params' => ['match' => $this->id],
                'variant' => 'primary',
            ],
            MatchStage::Scores => [
                'label' => 'Import scores from Impact',
                'detail' => 'Upload the Impact export to publish results and finalise the payout.',
                'route' => 'matches.scores',
                'params' => ['match' => $this->id],
                'variant' => 'primary',
            ],
            MatchStage::Results => $payout
                ? [
                    'label' => 'View payout statement',
                    'detail' => 'Payout '.$payout->reference.' already requested.',
                    'route' => 'matches.finances',
                    'params' => ['match' => $this->id],
                    'variant' => 'primary',
                ]
                : [
                    'label' => 'Request MD payout',
                    'detail' => 'Fill in your banking details and raise the payout statement.',
                    'route' => 'matches.request-payout',
                    'params' => ['match' => $this->id],
                    'variant' => 'primary',
                ],
            MatchStage::Payout => $payout
                ? [
                    'label' => 'View payout statement',
                    'detail' => $this->payoutStatusLine().' · '.$payout->reference,
                    'route' => 'matches.finances',
                    'params' => ['match' => $this->id],
                    'variant' => $payout->status === 'paid' ? 'ghost' : 'primary',
                ]
                : null,
        };
    }
}
