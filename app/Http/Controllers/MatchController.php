<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreMatchRequest;
use App\Http\Requests\UpdateMatchRequest;
use App\Models\Division;
use App\Models\MatchEvent;
use App\Models\MatchRegistration;
use App\Models\Province;
use App\Models\User;
use App\Models\Venue;
use App\Notifications\MatchRegistrationConfirmedNotification;
use App\Services\AuditLogService;
use App\Services\FinancialService;
use App\Services\GuestShooterService;
use App\Services\MembershipValidationService;
use App\Services\RegistrationPricingService;
use App\Services\SettingsService;
use App\Services\StandingsCalculationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
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

        // Keep the public listing flag in lockstep with status. store() already
        // does this; without it here, reverting Open → Draft left the match
        // on the public calendar with registration closed.
        $validated['published'] = ($validated['status'] ?? $match->status) !== 'draft';

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

    /**
     * Marks a match as completed AND creates a pending match-director payout in
     * one step. Triggered from the score-import success page so an MD who has
     * just uploaded results can close out the match and file for payment in a
     * single click, instead of chasing an admin.
     *
     * Guarded so the same match can't be double-requested: if the match is
     * already completed or already has an MD payout row, we short-circuit with
     * a soft warning rather than creating a duplicate.
     */
    public function completeAndRequestPayout(
        Request $request,
        MatchEvent $match,
        FinancialService $financials,
    ): RedirectResponse {
        $this->authorize('update', $match);

        $back = url()->previous() ?: route('matches.show', $match);

        $alreadyHasPayout = $match->payouts()
            ->where('payee_type', 'match_director')
            ->exists();

        if ($alreadyHasPayout) {
            return redirect($back)->with('success', 'A match director payout has already been requested for this match.');
        }

        $old = $match->only(['status', 'published']);

        if ($match->status !== 'completed') {
            $match->update(['status' => 'completed']);
        }

        $payout = $financials->createMdPayout(
            $match,
            $request->user(),
            'Requested by match director after score upload.',
        );

        $this->auditLogService->log(
            $request->user(),
            'md_payout_requested',
            'payout',
            $payout->id,
            $old,
            [
                'match_id' => $match->id,
                'payout_reference' => $payout->reference,
                'net_amount' => $payout->net_amount,
            ],
        );

        return redirect($back)->with(
            'success',
            "Match marked completed and payout {$payout->reference} requested for R" . number_format($payout->net_amount, 2) . '. An admin will process the payment.',
        );
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
        if ($match->status === 'draft' || ! $match->published) {
            abort(404);
        }

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
        // Default order is division (display_order), then shooter name — the
        // event page lets visitors re-sort client-side from there.
        $entries = $match->registrations()
            ->where('registration_status', '!=', 'cancelled')
            ->with('user:id,name,province_id', 'user.province:id,name', 'division:id,name,display_order')
            ->get()
            ->sortBy(function ($entry) {
                $order = $entry->division?->display_order ?? 999;
                $name = strtolower((string) ($entry->user?->name ?? $entry->shooter_name ?? ''));

                return sprintf('%04d-%s', $order, $name);
            })
            ->values();

        // Reconciliation summary shown above the entrant list once results
        // are up: how many entered vs how many actually scored vs how many
        // registered but didn't shoot. Walk-ins (score present, no entry)
        // slot in here as soon as MD confirms them via the walk-in flow.
        $scoredUserIds = $match->scores->pluck('user_id')->filter()->unique();
        $entrantUserIds = $entries->pluck('user_id')->filter()->unique();
        $reconciliation = [
            'entered' => $entries->count(),
            'scored' => $scoredUserIds->count(),
            'no_shows' => $entrantUserIds->diff($scoredUserIds)->count(),
            'walk_ins' => $scoredUserIds->diff($entrantUserIds)->count(),
        ];

        return view('events.show', compact('match', 'userRegistration', 'divisions', 'entries', 'reconciliation'));
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
        /** @var \App\Models\User $actor */
        $actor = $request->user();

        // Sponsor path for a shooter not yet on the platform: display the
        // register page with an in-memory User carrying the name/email the
        // sponsor typed. We deliberately DO NOT persist the stub here —
        // the record is only created on POST, so an abandoned preview
        // doesn't leave a ghost account behind.
        $newShooterName = trim((string) $request->input('new_shooter_name', ''));
        $newShooterEmail = trim((string) $request->input('new_shooter_email', ''));
        $isNewShooter = $newShooterName !== '' && ! $request->filled('for_user');

        if ($isNewShooter) {
            $shooter = new User([
                'name' => $newShooterName,
                'email' => $newShooterEmail !== '' ? $newShooterEmail : null,
            ]);
        } else {
            // Resolve who we're registering: self, one of the actor's managed
            // family accounts, or an independent member the actor is sponsoring.
            $shooter = $this->resolveShooter($actor, $request->input('for_user'));

            $existing = $match->userRegistration($shooter);
            if ($existing) {
                $isSelf = $shooter->id === $actor->id;

                return redirect()->route('registrations.show', $existing)
                    ->with('info', $isSelf
                        ? 'You are already registered for this match.'
                        : $shooter->name . ' is already registered for this match.');
            }
        }

        if (! $match->hasRequiredSetup()) {
            return redirect()->route('events.show', $match)
                ->with('info', 'This match isn’t open for sign-up yet — the match director still needs to set the entry fee and details.');
        }

        // A brand-new stub carries no membership yet, so pricing must not
        // consult it — pass null so the classifier returns `non_member`.
        // Existing shooters use their real membership as usual.
        $pricingSubject = $isNewShooter ? null : $shooter;

        $pricing = app(RegistrationPricingService::class)
            ->determineCategoryAndFee($match, $pricingSubject, $match->match_date);

        // Managed juniors typically shoot the parent's setup, so we show the
        // actor's own rifles for that flow. Independent shooters — self and
        // sponsored — pick from their own list. A brand-new sponsored
        // shooter obviously has no rifles yet; the sponsor picks a division
        // and the shooter chooses their rifle later.
        $rifleOwner = $isNewShooter ? null : ($this->isManagedShooter($shooter, $actor) ? $actor : $shooter);

        $rifles = $rifleOwner
            ? $rifleOwner->rifleConfigurations()
                ->where('is_active', true)
                ->with(['make', 'model', 'calibre'])
                ->orderMainsFirst($match->series ?? $match->match_type)
                ->get()
            : collect();

        $defaultRifleId = $rifles->firstWhere('primary_series', $match->series ?? $match->match_type)?->id;

        $juniors = $actor->managedAccounts()->orderBy('name')->get();

        $divisions = $match->availableDivisions();

        // Junior-division entries may carry a discounted fee — surface both the
        // normal and junior totals so the form can switch the price live.
        $juniorPricing = $match->junior_fee !== null
            ? app(RegistrationPricingService::class)->determineCategoryAndFee($match, $pricingSubject, $match->match_date, 'junior')
            : null;
        $juniorDivisionId = $divisions->firstWhere('slug', 'junior')?->id;

        return view('events.register', compact('match', 'pricing', 'rifles', 'defaultRifleId', 'shooter', 'juniors', 'divisions', 'juniorPricing', 'juniorDivisionId', 'isNewShooter'));
    }

    public function storeRegistration(Request $request, MatchEvent $match): RedirectResponse
    {
        /** @var \App\Models\User $actor */
        $actor = $request->user();

        // Two shooter-resolution paths on POST:
        //   • existing account (self / managed / sponsored existing) via
        //     the `for_user` field (empty means self)
        //   • sponsor-of-someone-not-on-the-platform via `new_shooter_name`
        //     (+ optional email). We eagerly find-or-create a stub only
        //     now, not on the GET preview.
        $newShooterName = trim((string) $request->input('new_shooter_name', ''));
        $newShooterEmail = trim((string) $request->input('new_shooter_email', ''));
        $isNewShooterSubmit = $newShooterName !== '' && ! $request->filled('for_user');

        if ($isNewShooterSubmit) {
            $request->validate([
                'new_shooter_name' => ['required', 'string', 'min:2', 'max:100'],
                'new_shooter_email' => ['nullable', 'email', 'max:150'],
            ], [
                'new_shooter_name.min' => 'A shooter name of at least 2 characters is required.',
                'new_shooter_email.email' => 'Enter a valid email address (or leave it blank).',
            ]);

            $shooter = app(GuestShooterService::class)->findOrCreate(
                $newShooterName,
                $newShooterEmail !== '' ? $newShooterEmail : null,
            );
        } else {
            $shooter = $this->resolveShooter($actor, $request->input('for_user'));
        }

        $existing = $match->userRegistration($shooter);
        if ($existing) {
            $isSelf = $shooter->id === $actor->id;

            return redirect()->route('registrations.show', $existing)
                ->with('info', $isSelf
                    ? 'You are already registered for this match.'
                    : $shooter->name . ' is already registered for this match.');
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

        $isManaged = $this->isManagedShooter($shooter, $actor);
        $isSelf = $shooter->id === $actor->id;
        // Sponsor = a logged-in member entering another independent member
        // (not themselves, not one of their own managed family accounts).
        $isSponsored = ! $isSelf && ! $isManaged;

        // Managed juniors carry a placeholder email — we contact the parent
        // instead. Sponsored shooters are real accounts, so we contact them
        // directly (with a cc to the sponsor implied by the sponsor's own
        // notification path).
        $contactEmail = $isManaged ? $actor->email : $shooter->email;
        $contactPhone = $isManaged ? ($actor->phone ?: $shooter->phone) : $shooter->phone;

        // Track the account that took the action, so the registration page can
        // surface "Entered by …" for sponsors and parents.
        $registeredById = $isSelf ? null : $actor->id;

        $registration = \App\Models\MatchRegistration::query()->create([
            'match_id' => $match->id,
            'user_id' => $shooter->id,
            'registered_by_user_id' => $registeredById,
            'rifle_configuration_id' => $isSponsored ? null : $request->input('rifle_configuration_id'),
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
            $actor,
            'registration.created',
            'MatchRegistration',
            $registration->id,
            null,
            array_merge($registration->toArray(), [
                'registered_for_user_id' => $shooter->id,
                'registered_by_parent_id' => $isManaged ? $actor->id : null,
                'registered_by_sponsor_id' => $isSponsored ? $actor->id : null,
            ]),
        );

        try {
            // Managed juniors: notify the parent (junior has placeholder email).
            // Sponsored: notify the shooter directly and mention the sponsor.
            // Self: notify the shooter (which is the actor).
            if ($isManaged) {
                $actor->notify(new MatchRegistrationConfirmedNotification($registration));
            } elseif ($isSponsored) {
                $shooter->notify(new MatchRegistrationConfirmedNotification($registration, $actor));
            } else {
                $shooter->notify(new MatchRegistrationConfirmedNotification($registration));
            }
        } catch (\Throwable $e) {
            Log::warning('Failed to send match registration notification', ['error' => $e->getMessage()]);
        }

        $payFastService = app(\App\Services\PayFastService::class);

        if ($payFastService->isEnabled() && $breakdown['total_fee'] > 0) {
            // Payment is made by whoever initiated the entry: the parent for a
            // managed junior, the sponsor when sponsoring, or the shooter
            // themselves. Never a stranger.
            $payer = $isSelf ? $shooter : $actor;

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

    /**
     * Match-director / admin action from the match edit page: seed a shooter
     * directly into the entry list as paid + confirmed. Never touches
     * PayFast — used when the entry fee was collected off-platform (cash,
     * EFT, comp'd by the MD, etc.). Optionally waives a lapsed-member
     * surcharge if the operator supplies a written reason.
     *
     * Same policy gate as `update()`: MD-of-this-match, owner, admin,
     * exco, developer.
     */
    public function storeAdminEntry(Request $request, MatchEvent $match): RedirectResponse
    {
        $this->authorize('update', $match);

        $allowedDivisionIds = $match->availableDivisions()->pluck('id')->all();

        // The MD picks EITHER an existing shooter (by user_id, typically
        // returned from the member search) OR a brand-new shooter whose
        // stub account we provision here (name required, email optional).
        // Exactly one of user_id / new_shooter_name must be supplied.
        $hasUserId = $request->filled('user_id');
        $hasNewName = trim((string) $request->input('new_shooter_name', '')) !== '';

        if (! $hasUserId && ! $hasNewName) {
            return back()
                ->withInput()
                ->withErrors(['user_id' => 'Pick an existing shooter or enter a new one.']);
        }

        if ($hasUserId && $hasNewName) {
            return back()
                ->withInput()
                ->withErrors(['user_id' => 'Choose only one: search for an existing shooter, OR add a new one.']);
        }

        $validated = $request->validate([
            'user_id' => ['nullable', 'required_without:new_shooter_name', 'integer', 'exists:users,id'],
            'new_shooter_name' => ['nullable', 'required_without:user_id', 'string', 'min:2', 'max:100'],
            'new_shooter_email' => ['nullable', 'email', 'max:150'],
            'division_id' => ['required', 'integer', Rule::in($allowedDivisionIds)],
            'waive_lapsed_surcharge' => ['sometimes', 'boolean'],
            'fee_override_reason' => ['nullable', 'string', 'max:500'],
        ], [
            'division_id.in' => 'The selected division is not available for this match.',
            'user_id.exists' => 'That member no longer exists.',
            'new_shooter_name.min' => 'A shooter name of at least 2 characters is required.',
            'new_shooter_email.email' => 'Enter a valid email address (or leave it blank).',
        ]);

        $waiveLapsed = (bool) ($validated['waive_lapsed_surcharge'] ?? false);
        $reason = trim((string) ($validated['fee_override_reason'] ?? ''));

        // Waiving a surcharge without a written reason leaves the entry
        // indistinguishable from a normal paid one — reject early so the
        // audit trail on the row explains WHY the shooter did not pay the
        // rate their membership implied.
        if ($waiveLapsed && $reason === '') {
            return back()
                ->withInput()
                ->withErrors(['fee_override_reason' => 'A reason is required when waiving the lapsed-member surcharge.']);
        }

        if ($hasUserId) {
            $shooter = User::query()->findOrFail($validated['user_id']);
        } else {
            // Brand-new sponsored/comp'd shooter. Provisions a minimal stub
            // (unclaimed account, free membership, non-member fee bracket)
            // that the shooter can later claim via forgot-password if a
            // real email was supplied.
            $newEmail = trim((string) ($validated['new_shooter_email'] ?? ''));
            $shooter = app(GuestShooterService::class)->findOrCreate(
                $validated['new_shooter_name'],
                $newEmail !== '' ? $newEmail : null,
            );
        }

        // Managed juniors belong to a family — a match director should not be
        // able to reach around the parent to seed them. The family flow on the
        // register page is the correct path for those accounts.
        if ($shooter->is_managed_account) {
            return back()
                ->withInput()
                ->withErrors(['user_id' => 'Managed family accounts (juniors, etc.) must be entered via their parent, not from here.']);
        }

        if (! $shooter->is_active) {
            return back()
                ->withInput()
                ->withErrors(['user_id' => 'That member is inactive and cannot be entered.']);
        }

        if ($existing = $match->userRegistration($shooter)) {
            return redirect()
                ->route('matches.edit', $match)
                ->with('info', $shooter->name . ' is already registered for this match (entry #' . $existing->id . ').');
        }

        // Only "force" the pricing bracket to active_member when the
        // shooter is actually lapsed and the operator ticked the box.
        // For non-members or already-active members we let the normal
        // classification stand — waiving the box then does nothing, which
        // is honest UX.
        $currentCategory = app(MembershipValidationService::class)
            ->classifyRegistrationCategory($shooter, $match->match_date);
        $forcedCategory = ($waiveLapsed && $currentCategory === 'lapsed_member') ? 'active_member' : null;

        $division = Division::findOrFail($validated['division_id']);
        $divisionSlug = $division->slug;

        $breakdown = app(RegistrationPricingService::class)
            ->calculateBreakdown($match, $shooter, $match->match_date ?: now(), $divisionSlug, $forcedCategory);

        // Manually seeded entries never pass through PayFast, so we must not
        // book the card-rate gateway estimate — otherwise the MD's payout for
        // this entry would silently be short by ~3.5% + R2 for money that was
        // handed to them in cash / EFT. Rebalance md_net accordingly.
        $gatewayFee = 0.00;
        $mdNet = round(
            (float) $breakdown['total_fee']
            - (float) $breakdown['saprf_fee']
            - (float) $breakdown['platform_fee']
            - (float) $breakdown['surcharge']
            - $gatewayFee,
            2
        );

        $registration = MatchRegistration::query()->create([
            'match_id' => $match->id,
            'user_id' => $shooter->id,
            'registered_by_user_id' => $request->user()->id,
            'division_id' => $division->id,
            'shooter_name' => $shooter->name,
            'email' => $shooter->email,
            'phone' => $shooter->phone,
            'membership_fee_category' => $breakdown['category'],
            'fee_amount' => $breakdown['total_fee'],
            'surcharge_amount' => $breakdown['surcharge'],
            'saprf_fee' => $breakdown['saprf_fee'],
            'platform_fee' => $breakdown['platform_fee'],
            'gateway_fee' => $gatewayFee,
            'md_net_amount' => $mdNet,
            'fee_override_reason' => $reason !== '' ? $reason : null,
            'payment_status' => 'paid',
            'registration_status' => 'confirmed',
            'registered_at' => now(),
        ]);

        $this->auditLogService->log(
            $request->user(),
            'registration.admin_added',
            'MatchRegistration',
            $registration->id,
            null,
            array_merge($registration->toArray(), [
                'forced_category' => $forcedCategory,
                'seeded_via' => 'match_edit_ui',
            ]),
        );

        return redirect()
            ->route('matches.edit', $match)
            ->with('success', $shooter->name . ' added to the entry list (confirmed, paid).');
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
     * Resolve the shooter for a registration. Three cases:
     *
     *  1. `for_user` empty → the actor themselves.
     *  2. `for_user` is one of the actor's managed family accounts → that
     *     junior. Family semantics apply (parent contact, parent's rifles).
     *  3. `for_user` is any other active, non-managed member → sponsor path.
     *     The actor is entering / paying for someone else on this match.
     *
     * Managed accounts owned by a different parent are never allowed —
     * they belong to that other family, not to the actor.
     */
    private function resolveShooter(\App\Models\User $actor, ?string $forUserId): \App\Models\User
    {
        if (! $forUserId || (string) $forUserId === (string) $actor->id) {
            return $actor;
        }

        $target = \App\Models\User::query()
            ->where('id', $forUserId)
            ->first();

        if (! $target) {
            abort(404, 'Member not found.');
        }

        // A managed account is either the actor's own family member (allowed)
        // or someone else's junior (never allowed via this endpoint).
        if ($target->is_managed_account) {
            if ($target->parent_id === $actor->id) {
                return $target;
            }

            abort(403, 'You can only register your own family accounts.');
        }

        // Sponsor path: any active, non-managed member the actor picks by
        // name/SAPRF number. Inactive accounts are excluded so a sponsor
        // cannot resurrect a lapsed record.
        if (! $target->is_active) {
            abort(403, 'That member is not currently active.');
        }

        return $target;
    }

    private function isManagedShooter(\App\Models\User $shooter, \App\Models\User $parent): bool
    {
        return $shooter->id !== $parent->id
            && $shooter->is_managed_account
            && $shooter->parent_id === $parent->id;
    }
}
