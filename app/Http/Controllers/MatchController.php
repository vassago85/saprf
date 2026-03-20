<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreMatchRequest;
use App\Http\Requests\UpdateMatchRequest;
use App\Models\MatchEvent;
use App\Models\Province;
use App\Services\AuditLogService;
use App\Services\RegistrationPricingService;
use App\Services\SettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MatchController extends Controller
{
    public function __construct(
        private readonly AuditLogService $auditLogService,
        private readonly SettingsService $settingsService,
    ) {}

    // ── Admin CRUD (authenticated) ──

    public function index(Request $request): View
    {
        $matches = MatchEvent::query()
            ->with(['province', 'creator'])
            ->latest('match_date')
            ->paginate(20);

        return view('matches.index', compact('matches'));
    }

    public function create(): View
    {
        $provinces = Province::orderBy('name')->get();

        return view('matches.create', compact('provinces'));
    }

    public function store(StoreMatchRequest $request): RedirectResponse
    {
        $validated = $request->validated();
        $baseFee = (float) ($validated['active_member_fee'] ?? 0);
        $nonMemberSurcharge = (float) $this->settingsService->get('non_member_surcharge', 0);
        $lapsedSurcharge = (float) $this->settingsService->get('lapsed_member_surcharge', 0);

        $match = MatchEvent::query()->create([
            ...$validated,
            'series' => $validated['match_type'],
            'season' => (string) now()->year,
            'created_by' => $request->user()->id,
            'non_member_fee' => $baseFee + $nonMemberSurcharge,
            'lapsed_member_fee' => $baseFee + $lapsedSurcharge,
            'published' => ($validated['status'] ?? 'draft') !== 'draft',
        ]);

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

    public function show(MatchEvent $match): View
    {
        $match->load(['province', 'creator', 'registrations', 'scoreImports']);

        return view('matches.show', compact('match'));
    }

    public function edit(MatchEvent $match): View
    {
        $this->authorize('update', $match);

        $provinces = Province::orderBy('name')->get();

        return view('matches.edit', compact('match', 'provinces'));
    }

    public function update(UpdateMatchRequest $request, MatchEvent $match): RedirectResponse
    {
        $old = $match->toArray();

        $match->update($request->validated());

        $this->auditLogService->log(
            $request->user(),
            'match.updated',
            'MatchEvent',
            $match->id,
            $old,
            $match->fresh()->toArray(),
        );

        return redirect()->route('matches.show', $match)
            ->with('success', 'Match updated successfully.');
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
        $sort = $request->input('sort', 'date_asc');
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
                ->with(['scores' => fn ($q) => $q->where('status', 'valid')->orderBy('placement')->limit(3)])
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
        $match->load(['province', 'creator:id,name', 'scores' => fn ($q) => $q->where('status', 'valid')->orderBy('placement')]);
        $match->loadCount(['registrations', 'scores']);

        $userRegistration = Auth::check()
            ? $match->userRegistration(Auth::user())
            : null;

        return view('events.show', compact('match', 'userRegistration'));
    }

    public function publicCalendarData(Request $request): JsonResponse
    {
        $month = (int) $request->input('month', now()->month);
        $year = (int) $request->input('year', now()->year);
        $discipline = $request->input('discipline');

        $start = now()->setDate($year, $month, 1)->startOfMonth()->subDays(6);
        $end = now()->setDate($year, $month, 1)->endOfMonth()->addDays(6);

        $events = MatchEvent::query()
            ->published()
            ->with(['province', 'creator:id,name'])
            ->whereBetween('match_date', [$start, $end])
            ->forDiscipline($discipline)
            ->orderBy('match_date')
            ->get(['id', 'name', 'match_type', 'series_level', 'province_id', 'match_date', 'status', 'venue_name', 'venue_location', 'city', 'registration_close_date', 'max_competitors', 'waitlist_enabled', 'active_member_fee', 'non_member_fee', 'created_by']);

        $grouped = $events->groupBy(fn ($e) => $e->match_date->format('Y-m-d'))->map(function ($dayEvents) {
            return $dayEvents->map(fn ($e) => [
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
            ]);
        });

        return response()->json(['events' => $grouped]);
    }

    // ── Registration Flow ──

    public function showRegistration(MatchEvent $match): View|RedirectResponse
    {
        /** @var \App\Models\User $user */
        $user = Auth::user();

        $existing = $match->userRegistration($user);
        if ($existing) {
            return redirect()->route('registrations.show', $existing)
                ->with('info', 'You are already registered for this match.');
        }

        $pricing = app(RegistrationPricingService::class)
            ->determineCategoryAndFee($match, $user, $match->match_date);

        $rifles = $user->rifleConfigurations()
            ->where('is_active', true)
            ->with(['make', 'model', 'calibre'])
            ->orderByDesc('is_primary')
            ->get();

        return view('events.register', compact('match', 'pricing', 'rifles'));
    }

    public function storeRegistration(Request $request, MatchEvent $match): RedirectResponse
    {
        $user = $request->user();

        $existing = $match->userRegistration($user);
        if ($existing) {
            return redirect()->route('registrations.show', $existing)
                ->with('info', 'You are already registered for this match.');
        }

        if (! $match->isRegistrationOpen() && ! $match->isWaitlistOpen()) {
            return back()->with('error', 'Registration is not available for this match.');
        }

        $request->validate([
            'rifle_configuration_id' => ['nullable', 'exists:rifle_configurations,id'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $pricing = app(RegistrationPricingService::class)
            ->determineCategoryAndFee($match, $user, $match->match_date);

        $regStatus = $match->isFull() && $match->waitlist_enabled ? 'waitlisted' : 'pending';

        $registration = \App\Models\MatchRegistration::query()->create([
            'match_id' => $match->id,
            'user_id' => $user->id,
            'rifle_configuration_id' => $request->input('rifle_configuration_id'),
            'shooter_name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'membership_fee_category' => $pricing['category'],
            'fee_amount' => $pricing['fee'],
            'payment_status' => 'unpaid',
            'registration_status' => $regStatus,
            'registered_at' => now(),
        ]);

        $this->auditLogService->log(
            $user,
            'registration.created',
            'MatchRegistration',
            $registration->id,
            null,
            $registration->toArray(),
        );

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
            ->get(['id', 'name', 'slug', 'match_type', 'series_level', 'province_id', 'match_date', 'venue_name', 'venue_location', 'city', 'status', 'is_featured', 'active_member_fee', 'non_member_fee', 'max_competitors', 'registration_close_date']);

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
}
