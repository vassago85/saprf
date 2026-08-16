<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreMatchRequest;
use App\Http\Requests\UpdateMatchRequest;
use App\Models\MatchEvent;
use App\Models\Province;
use App\Models\Venue;
use App\Notifications\MatchRegistrationConfirmedNotification;
use App\Services\AuditLogService;
use App\Services\RegistrationPricingService;
use App\Services\SettingsService;
use App\Services\StandingsCalculationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MatchController extends Controller
{
    public function __construct(
        private readonly AuditLogService $auditLogService,
        private readonly SettingsService $settingsService,
        private readonly StandingsCalculationService $standings,
    ) {}

    // ── Admin CRUD (authenticated) ──

    public function index(Request $request): View
    {
        $user = $request->user();

        $query = MatchEvent::query()
            ->with(['province', 'creator'])
            ->latest('match_date');

        if ($user->hasRole('match_director') && ! $user->hasAnyRole(['owner', 'admin'])) {
            $query->where('created_by', $user->id);
        }

        $matches = $query->paginate(20);

        return view('matches.index', compact('matches'));
    }

    public function create(): View
    {
        $provinces = Province::orderBy('name')->get();
        $venues = Venue::active()->with('province')->orderBy('name')->get();

        return view('matches.create', compact('provinces', 'venues'));
    }

    public function store(StoreMatchRequest $request): RedirectResponse
    {
        $validated = $request->validated();
        $divisionIds = $validated['divisions'] ?? [];
        unset($validated['divisions']);
        $baseFee = (float) ($validated['active_member_fee'] ?? 0);
        $nonMemberSurcharge = (float) $this->settingsService->get('non_member_surcharge', 0);
        $lapsedSurcharge = (float) $this->settingsService->get('lapsed_member_surcharge', 0);

        $validated['non_member_fee'] = $baseFee + $nonMemberSurcharge;
        $validated['lapsed_member_fee'] = $baseFee + $lapsedSurcharge;
        $validated['series'] = $validated['match_type'];
        $validated['season'] = (string) now()->year;
        $validated['created_by'] = $request->user()->id;
        $validated['published'] = ($validated['status'] ?? 'draft') !== 'draft';

        // Every match needs a named director; default to the creating account so
        // the match can be opened for sign-up without an extra step.
        if (empty($validated['match_director'])) {
            $validated['match_director'] = $request->user()->name;
        }

        $match = MatchEvent::query()->create($validated);

        $match->divisions()->sync($divisionIds);

        $this->auditLogService->log(
            $request->user(),
            'match.created',
            'MatchEvent',
            $match->id,
            null,
            $match->toArray(),
        );

        return redirect()->route('matches.show', $match)
            ->with('success', 'Match created successfully.');
    }

    public function show(MatchEvent $match): View|RedirectResponse
    {
        $user = Auth::user();

        // Regular members don't manage matches — send them to the public event page.
        if (! $user || ! $user->hasAnyRole(['developer', 'exco', 'owner', 'admin', 'match_director'])) {
            return redirect()->route('events.show', $match);
        }

        $this->authorize('view', $match);

        $match->load(['province', 'creator', 'registrations', 'scoreImports', 'expenses.creator']);

        $financeBreakdown = null;
        $planningEstimate = null;

        if ($user->hasAnyRole(['owner', 'admin', 'developer', 'exco']) || $match->created_by === $user->id) {
            $paidRegistrations = $match->registrations
                ->where('registration_status', '!=', 'cancelled');

            $financeBreakdown = [
                'total_collected' => $paidRegistrations->sum('fee_amount'),
                'total_saprf_fee' => $paidRegistrations->sum('saprf_fee'),
                'total_platform_fee' => $paidRegistrations->sum('platform_fee'),
                'total_surcharges' => $paidRegistrations->sum('surcharge_amount'),
                'total_gateway_fee' => $paidRegistrations->sum('gateway_fee'),
                'total_md_net' => $paidRegistrations->sum('md_net_amount'),
                'registration_count' => $paidRegistrations->count(),
            ];

            $saprfType = (string) $this->settingsService->get('saprf_fee_type', 'percentage');
            $saprfValue = (float) $this->settingsService->get('saprf_fee_value', $this->settingsService->get('saprf_fee_percentage', 5));
            $platformType = (string) $this->settingsService->get('platform_fee_type', 'percentage');
            $platformValue = (float) $this->settingsService->get('platform_fee_value', $this->settingsService->get('platform_fee_percentage', 5));
            $gatewayPct = (float) $this->settingsService->get('estimated_gateway_fee_percentage', 3.5) / 100;
            $baseFee = (float) $match->active_member_fee;
            $capacity = $match->estimated_shooters ?: ($match->max_competitors ?: 30);

            $saprfPerShooter = $saprfType === 'fixed' ? $saprfValue : $baseFee * ($saprfValue / 100);
            $platformPerShooter = $platformType === 'fixed' ? $platformValue : $baseFee * ($platformValue / 100);

            $grossRevenue = $baseFee * $capacity;
            $saprfFee = $saprfPerShooter * $capacity;
            $platformFee = $platformPerShooter * $capacity;
            $gatewayFee = $grossRevenue * $gatewayPct;
            $mdNet = $grossRevenue - $saprfFee - $platformFee - $gatewayFee;

            $planningEstimate = [
                'capacity' => $capacity,
                'base_fee' => $baseFee,
                'gross_revenue' => $grossRevenue,
                'saprf_fee' => $saprfFee,
                'saprf_type' => $saprfType,
                'saprf_value' => $saprfValue,
                'platform_fee' => $platformFee,
                'platform_type' => $platformType,
                'platform_value' => $platformValue,
                'gateway_fee' => $gatewayFee,
                'gateway_pct' => $gatewayPct * 100,
                'md_net' => $mdNet,
            ];
        }

        $expenses = $match->expenses->sortByDesc('created_at');
        $estimatedShooters = $match->estimated_shooters ?: ($match->max_competitors ?: 0);
        $totalExpenses = $expenses->sum(fn ($e) => $e->effectiveAmount($estimatedShooters));

        return view('matches.show', compact('match', 'financeBreakdown', 'planningEstimate', 'expenses', 'totalExpenses', 'estimatedShooters'));
    }

    public function edit(MatchEvent $match): View
    {
        $this->authorize('update', $match);

        $provinces = Province::orderBy('name')->get();
        $venues = Venue::active()->with('province')->orderBy('name')->get();
        $settings = $this->settingsService->all();

        return view('matches.edit', compact('match', 'provinces', 'venues', 'settings'));
    }

    public function update(UpdateMatchRequest $request, MatchEvent $match): RedirectResponse
    {
        $old = $match->toArray();

        $validated = $request->validated();
        $divisionIds = $validated['divisions'] ?? [];
        unset($validated['divisions']);

        $baseFee = (float) ($validated['active_member_fee'] ?? $match->active_member_fee ?? 0);
        $nonMemberSurcharge = (float) $this->settingsService->get('non_member_surcharge', 0);
        $lapsedSurcharge = (float) $this->settingsService->get('lapsed_member_surcharge', 0);

        $validated['non_member_fee'] = $baseFee + $nonMemberSurcharge;
        $validated['lapsed_member_fee'] = $baseFee + $lapsedSurcharge;

        // Fee overrides are exco/developer only. Silently drop the fields for
        // anyone else so an ordinary MD tampering with the payload can't
        // rewrite the split. Read-only UI already keeps them from being able
        // to submit these, but defence-in-depth.
        if (! $request->user()->hasAnyRole(['exco', 'developer'])) {
            unset(
                $validated['platform_fee_type'],
                $validated['platform_fee_value'],
                $validated['saprf_fee_type'],
                $validated['saprf_fee_value'],
            );
        }

        // "Everyone counts" bypasses the membership check on every score in
        // this match, so an MD flipping it on their own match could grant
        // themselves eligibility they wouldn't otherwise have. Restrict to
        // owner/admin/exco/developer; drop the field silently for anyone else.
        if (! $request->user()->hasAnyRole(['owner', 'admin', 'exco', 'developer'])) {
            unset($validated['everyone_counts']);
        }

        $match->update($validated);

        $match->divisions()->sync($divisionIds);

        // Season logs are derived from a published match's scores, and almost
        // any edit (level, type, province, date, dual-count flags…) can
        // change how those scores rank or pool. Rather than guess
        // which fields matter, ANY edit to a published match rebuilds the
        // affected season logs (national + every provincial table) from the
        // persisted scores. Draft matches have no standings yet, so skip them.
        $recalculated = false;
        if ($match->published) {
            $this->standings->recalculateForMatch($match);
            $recalculated = true;
        }

        $this->auditLogService->log(
            $request->user(),
            'match.updated',
            'MatchEvent',
            $match->id,
            $old,
            $match->fresh()->toArray(),
        );

        return redirect()->route('matches.show', $match)
            ->with('success', 'Match updated successfully.'.($recalculated ? ' Standings were recalculated.' : ''));
    }

    public function exportImpactScoringCsv(MatchEvent $match): StreamedResponse
    {
        $this->authorize('update', $match);

        $registrations = $match->registrations()
            ->where('registration_status', '!=', 'cancelled')
            ->with(['user.province', 'user.membership'])
            ->orderBy('registered_at')
            ->get();

        $filename = str($match->name)->slug() . '-impact-scoring.csv';

        return response()->streamDownload(function () use ($registrations, $match) {
            $out = fopen('php://output', 'w');

            fputcsv($out, [
                'Email',
                'Name',
                'Phone',
                'Squad',
                'Division',
                'Member Number',
            ]);

            $squad = 1;
            foreach ($registrations as $reg) {
                $user = $reg->user;

                fputcsv($out, [
                    $reg->email ?: $user?->email ?: '',
                    $reg->shooter_name ?: $user?->name ?: '',
                    $reg->phone ?: $user?->phone ?: '',
                    (string) $squad,
                    $match->match_type ?: 'Open',
                    $user?->membership?->saprf_number ?: '',
                ]);

                $squad++;
            }

            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv',
        ]);
    }

    // ── Public Pages ──

    public function publicIndex(Request $request): View
    {
        $tab = $request->input('tab', 'upcoming');
        $discipline = $request->input('discipline');
        $type = $request->input('type');
        $provinceId = $request->filled('province_id') ? (int) $request->input('province_id') : null;
        $status = $request->input('status');
        $dateRange = $request->input('date_range');
        $search = $request->input('search');
        // Default sort depends on which tab you're looking at: the Upcoming
        // tab wants soonest-first (what's next?), the Results tab wants
        // latest-first (nobody scrolls back to the January opener looking
        // for last weekend's match). The explicit ?sort= param always wins.
        $defaultSort = $tab === 'results' ? 'date_desc' : 'date_asc';
        $sort = $request->input('sort', $defaultSort);
        $view = $request->input('view', 'list');
        $season = $request->input('season', (string) now()->year);

        $baseQuery = MatchEvent::query()
            ->published()
            ->with(['province', 'creator:id,name'])
            ->withCount('registrations')
            ->forDiscipline($discipline)
            ->forLevel($type)
            ->forProvince($provinceId)
            ->search($search);

        if ($tab === 'upcoming') {
            $query = (clone $baseQuery)->upcoming();

            if ($dateRange === 'this_month') {
                $query->whereBetween('match_date', [now()->startOfMonth(), now()->endOfMonth()]);
            } elseif ($dateRange === 'next_3_months') {
                $query->whereBetween('match_date', [now()->startOfDay(), now()->addMonths(3)->endOfDay()]);
            }

            if ($status && $status !== 'past') {
                $query->forStatus($status);
            }
        } else {
            $query = (clone $baseQuery)
                ->past()
                ->with(['scores' => fn ($q) => $q->whereIn('status', \App\Services\ScoreValidationService::VISIBLE_STATUSES)->orderBy('overall_rank')->limit(3)])
                ->when($season, fn ($q) => $q->where('season', $season));
        }

        $query = match ($sort) {
            'date_desc' => $query->latest('match_date'),
            'province' => $query->orderBy('province_id')->orderBy('match_date'),
            'closing_soon' => $query->orderByRaw('COALESCE(registration_close_date, match_date) ASC'),
            default => $query->orderBy('match_date'),
        };

        $events = $query->paginate(18)->withQueryString();

        $provinces = Province::orderBy('name')->get();
        $seasons = MatchEvent::where('status', 'completed')
            ->distinct()
            ->pluck('season')
            ->sort()
            ->reverse()
            ->values();

        if ($seasons->isEmpty()) {
            $seasons = collect([(string) now()->year]);
        }

        $upcomingCount = MatchEvent::published()->upcoming()->count();
        $pastCount = MatchEvent::published()->past()->count();

        $activeFilters = collect([
            'discipline' => $discipline,
            'type' => $type,
            'province_id' => $provinceId,
            'status' => $status,
            'date_range' => $dateRange,
            'search' => $search,
        ])->filter()->all();

        return view('events.index', compact(
            'events', 'tab', 'discipline', 'type', 'provinceId', 'status',
            'dateRange', 'search', 'sort', 'view', 'season', 'seasons',
            'provinces', 'upcomingCount', 'pastCount', 'activeFilters',
        ));
    }

    public function publicShow(MatchEvent $match): View
    {
        $match->load(['province', 'creator:id,name', 'scores' => fn ($q) => $q->whereIn('status', \App\Services\ScoreValidationService::VISIBLE_STATUSES)->with(['division'])->orderBy('overall_rank')]);
        // "Registered" must exclude withdrawn/cancelled entries so the stat matches
        // the public entrant list (which already hides cancelled registrations).
        $match->loadCount([
            'registrations' => fn ($q) => $q->where('registration_status', '!=', 'cancelled'),
            'scores',
        ]);

        $userRegistration = Auth::check()
            ? $match->userRegistration(Auth::user())
            : null;

        $divisions = $match->scores->pluck('division')->filter()->unique('id')->sortBy('display_order')->values();

        // Public entry list — everyone can see who has registered. Cancelled /
        // withdrawn entries are hidden; fees stay private to organisers.
        $entries = $match->registrations()
            ->where('registration_status', '!=', 'cancelled')
            ->with('user:id,name,province_id', 'user.province:id,name', 'division:id,name')
            ->orderBy('registered_at')
            ->get();

        return view('events.show', compact('match', 'userRegistration', 'divisions', 'entries'));
    }

    public function publicCalendarData(Request $request): JsonResponse
    {
        $month = (int) $request->input('month', now()->month);
        $year = (int) $request->input('year', now()->year);
        $discipline = $request->input('discipline');
        $provinceId = $request->input('province_id');
        $type = $request->input('type');

        $start = now()->setDate($year, $month, 1)->startOfMonth()->subDays(6);
        $end = now()->setDate($year, $month, 1)->endOfMonth()->addDays(6);

        $events = MatchEvent::query()
            ->published()
            ->with(['province', 'creator:id,name'])
            ->whereBetween('match_date', [$start, $end])
            ->forDiscipline($discipline)
            ->when($provinceId, fn ($q) => $q->where('province_id', $provinceId))
            ->when($type, fn ($q) => $q->where('match_type', $type))
            ->orderBy('match_date')
            ->get(['id', 'name', 'match_type', 'series_level', 'province_id', 'match_date', 'match_end_date', 'status', 'venue_name', 'venue_location', 'city', 'registration_close_date', 'max_competitors', 'waitlist_enabled', 'active_member_fee', 'non_member_fee', 'created_by']);

        $grouped = [];

        foreach ($events as $e) {
            $baseData = [
                'id' => $e->id,
                'name' => $e->name,
                'match_type' => $e->match_type,
                'series_level' => $e->series_level,
                'province' => $e->province?->name,
                'venue_name' => $e->venue_name,
                'location' => $e->location_display,
                'md' => $e->creator?->name,
                'status' => $e->registration_status,
                'match_status' => $e->status,
                'member_fee' => (float) $e->active_member_fee,
                'match_end_date' => $e->match_end_date?->format('Y-m-d'),
                'multi_day' => $e->isMultiDay(),
            ];

            $startDate = $e->match_date->copy();
            $endDate = $e->match_end_date ? $e->match_end_date->copy() : $startDate->copy();

            if ($startDate->eq($endDate)) {
                $key = $startDate->format('Y-m-d');
                $grouped[$key][] = array_merge($baseData, ['span_type' => 'single']);
            } else {
                $cursor = $startDate->copy();
                while ($cursor->lte($endDate)) {
                    $key = $cursor->format('Y-m-d');
                    $spanType = match (true) {
                        $cursor->eq($startDate) => 'start',
                        $cursor->eq($endDate) => 'end',
                        default => 'middle',
                    };
                    $grouped[$key][] = array_merge($baseData, ['span_type' => $spanType]);
                    $cursor->addDay();
                }
            }
        }

        return response()->json(['events' => $grouped]);
    }

    // ── Registration Flow ──

    public function showRegistration(Request $request, MatchEvent $match): View|RedirectResponse
    {
        /** @var \App\Models\User $parent */
        $parent = $request->user();

        // Resolve who we're registering: self, or one of the parent's juniors
        $shooter = $this->resolveShooter($parent, $request->input('for_user'));

        $existing = $match->userRegistration($shooter);
        if ($existing) {
            return redirect()->route('registrations.show', $existing)
                ->with('info', $this->isManagedShooter($shooter, $parent)
                    ? $shooter->name . ' is already registered for this match.'
                    : 'You are already registered for this match.');
        }

        if (! $match->hasRequiredSetup()) {
            return redirect()->route('events.show', $match)
                ->with('info', 'This match isn’t open for sign-up yet — the match director still needs to set the entry fee and details.');
        }

        $pricing = app(RegistrationPricingService::class)
            ->determineCategoryAndFee($match, $shooter, $match->match_date);

        // Rifles: managed accounts use the parent's rifles since juniors typically
        // shoot the parent's setup. Independent users use their own.
        $rifleOwner = $this->isManagedShooter($shooter, $parent) ? $parent : $shooter;

        $rifles = $rifleOwner->rifleConfigurations()
            ->where('is_active', true)
            ->with(['make', 'model', 'calibre'])
            ->orderByDesc('is_primary')
            ->get();

        $juniors = $parent->managedAccounts()->orderBy('name')->get();

        $divisions = $match->availableDivisions();

        // Junior-division entries may carry a discounted fee — surface both the
        // normal and junior totals so the form can switch the price live.
        $juniorPricing = $match->junior_fee !== null
            ? app(RegistrationPricingService::class)->determineCategoryAndFee($match, $shooter, $match->match_date, 'junior')
            : null;
        $juniorDivisionId = $divisions->firstWhere('slug', 'junior')?->id;

        return view('events.register', compact('match', 'pricing', 'rifles', 'shooter', 'juniors', 'divisions', 'juniorPricing', 'juniorDivisionId'));
    }

    public function storeRegistration(Request $request, MatchEvent $match): RedirectResponse
    {
        /** @var \App\Models\User $parent */
        $parent = $request->user();

        $shooter = $this->resolveShooter($parent, $request->input('for_user'));

        $existing = $match->userRegistration($shooter);
        if ($existing) {
            return redirect()->route('registrations.show', $existing)
                ->with('info', $this->isManagedShooter($shooter, $parent)
                    ? $shooter->name . ' is already registered for this match.'
                    : 'You are already registered for this match.');
        }

        if (! $match->isRegistrationOpen() && ! $match->isWaitlistOpen()) {
            return back()->with('error', 'Registration is not available for this match.');
        }

        $allowedDivisionIds = $match->availableDivisions()->pluck('id')->all();

        $validated = $request->validate([
            'rifle_configuration_id' => ['nullable', 'exists:rifle_configurations,id'],
            'division_id' => ['required', \Illuminate\Validation\Rule::in($allowedDivisionIds)],
            'notes' => ['nullable', 'string', 'max:500'],
        ], [
            'division_id.required' => 'Please choose a division to enter.',
            'division_id.in' => 'The selected division is not available for this match.',
        ]);

        $divisionSlug = \App\Models\Division::whereKey($validated['division_id'])->value('slug');

        $breakdown = app(RegistrationPricingService::class)
            ->calculateBreakdown($match, $shooter, $match->match_date, $divisionSlug);

        $regStatus = $match->isFull() && $match->waitlist_enabled ? 'waitlisted' : 'pending';

        // For managed juniors, we contact the parent (placeholder email won't deliver).
        $contactEmail = $this->isManagedShooter($shooter, $parent) ? $parent->email : $shooter->email;
        $contactPhone = $this->isManagedShooter($shooter, $parent) ? ($parent->phone ?: $shooter->phone) : $shooter->phone;

        $registration = \App\Models\MatchRegistration::query()->create([
            'match_id' => $match->id,
            'user_id' => $shooter->id,
            'rifle_configuration_id' => $request->input('rifle_configuration_id'),
            'division_id' => $validated['division_id'],
            'shooter_name' => $shooter->name,
            'email' => $contactEmail,
            'phone' => $contactPhone,
            'membership_fee_category' => $breakdown['category'],
            'fee_amount' => $breakdown['total_fee'],
            'surcharge_amount' => $breakdown['surcharge'],
            'saprf_fee' => $breakdown['saprf_fee'],
            'platform_fee' => $breakdown['platform_fee'],
            'gateway_fee' => $breakdown['gateway_fee'],
            'md_net_amount' => $breakdown['md_net'],
            'payment_status' => 'pending',
            'registration_status' => $regStatus,
            'registered_at' => now(),
        ]);

        $this->auditLogService->log(
            $parent,
            'registration.created',
            'MatchRegistration',
            $registration->id,
            null,
            array_merge($registration->toArray(), [
                'registered_for_user_id' => $shooter->id,
                'registered_by_parent_id' => $this->isManagedShooter($shooter, $parent) ? $parent->id : null,
            ]),
        );

        try {
            // Notify the parent for managed juniors (junior has placeholder email);
            // notify the shooter directly otherwise.
            $recipient = $this->isManagedShooter($shooter, $parent) ? $parent : $shooter;
            $recipient->notify(new MatchRegistrationConfirmedNotification($registration));
        } catch (\Throwable $e) {
            Log::warning('Failed to send match registration notification', ['error' => $e->getMessage()]);
        }

        $payFastService = app(\App\Services\PayFastService::class);

        if ($payFastService->isEnabled() && $breakdown['total_fee'] > 0) {
            // Payment is always made by the parent for managed juniors.
            $payer = $this->isManagedShooter($shooter, $parent) ? $parent : $shooter;

            $payment = \App\Models\Payment::create([
                'payable_type' => \App\Models\MatchRegistration::class,
                'payable_id' => $registration->id,
                'user_id' => $payer->id,
                'amount' => $breakdown['total_fee'],
                'm_payment_id' => \App\Models\Payment::generateReference('REG'),
            ]);

            return redirect()->route('payments.redirect', $payment);
        }

        $message = $regStatus === 'waitlisted'
            ? 'You have been added to the waitlist.'
            : 'Registration submitted successfully.';

        return redirect()->route('registrations.show', $registration)
            ->with('success', $message);
    }

    // ── API ──

    public function apiUpcoming(): JsonResponse
    {
        $matches = MatchEvent::query()
            ->published()
            ->with('province')
            ->where('match_date', '>=', now())
            ->where('status', 'open')
            ->orderBy('match_date')
            ->limit(6)
            ->get(['id', 'name', 'slug', 'match_type', 'series_level', 'province_id', 'match_date', 'venue_name', 'venue_location', 'city', 'status', 'active_member_fee', 'non_member_fee', 'max_competitors', 'registration_close_date']);

        return response()->json(['data' => $matches]);
    }

    public function apiRecentResults(): JsonResponse
    {
        $matches = MatchEvent::query()
            ->published()
            ->with('province')
            ->where('match_date', '<', now())
            ->whereHas('scores')
            ->latest('match_date')
            ->limit(10)
            ->get(['id', 'name', 'slug', 'match_type', 'series_level', 'province_id', 'match_date', 'venue_name', 'venue_location', 'city', 'status']);

        return response()->json($matches);
    }

    // ──────────────────────────────────────────────────────────────────────
    // Family / Managed Account helpers
    // ──────────────────────────────────────────────────────────────────────

    /**
     * Resolve the actual shooter for a registration. Defaults to the
     * authenticated user, but may be a managed junior they're registering on
     * behalf of (when `for_user` is supplied and points to one of their juniors).
     */
    private function resolveShooter(\App\Models\User $parent, ?string $forUserId): \App\Models\User
    {
        if (! $forUserId) {
            return $parent;
        }

        $junior = \App\Models\User::query()
            ->where('id', $forUserId)
            ->where('parent_id', $parent->id)
            ->where('is_managed_account', true)
            ->first();

        if (! $junior) {
            abort(403, 'You can only register your own family accounts.');
        }

        return $junior;
    }

    private function isManagedShooter(\App\Models\User $shooter, \App\Models\User $parent): bool
    {
        return $shooter->id !== $parent->id
            && $shooter->is_managed_account
            && $shooter->parent_id === $parent->id;
    }
}
