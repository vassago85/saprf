<?php

namespace App\Http\Controllers;

use App\Models\MatchEvent;
use App\Models\PlatformIncome;
use App\Models\Score;
use App\Models\Sponsor;
use App\Services\QualificationService;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportsController extends Controller
{
    public function __construct(
        private readonly QualificationService $qualificationService,
    ) {}

    // ──────────────────────────────────────────────────────────────────────
    // Reports Hub
    // ──────────────────────────────────────────────────────────────────────

    public function index(): View
    {
        return view('reports.index');
    }

    // ──────────────────────────────────────────────────────────────────────
    // Sponsorship Report
    // ──────────────────────────────────────────────────────────────────────

    public function sponsorship(Request $request): View
    {
        [$from, $to] = $this->parseDateRange($request);

        $incomeQuery = PlatformIncome::query()
            ->where('category', 'sponsorship')
            ->with(['sponsor.tier'])
            ->when($from, fn ($q) => $q->whereDate('income_date', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('income_date', '<=', $to))
            ->orderByDesc('income_date');

        $incomeItems = $incomeQuery->get();

        // Group payments by sponsor
        $bySponsor = $incomeItems
            ->groupBy(fn ($i) => $i->sponsor?->id ?? 'unlinked')
            ->map(function ($items) {
                $first = $items->first();

                return [
                    'sponsor' => $first->sponsor,
                    'sponsor_name' => $first->sponsor?->name ?? ($first->source ?: 'Unlinked'),
                    'tier' => $first->sponsor?->tier?->name ?? '—',
                    'payment_count' => $items->count(),
                    'total' => $items->sum('amount'),
                    'first_payment' => $items->min('income_date'),
                    'latest_payment' => $items->max('income_date'),
                    'payments' => $items,
                ];
            })
            ->sortByDesc('total')
            ->values();

        $totalRevenue = (float) $incomeItems->sum('amount');
        $totalLinkedRevenue = (float) $incomeItems->where('sponsor_id', '!=', null)->sum('amount');
        $totalUnlinkedRevenue = $totalRevenue - $totalLinkedRevenue;

        $activeSponsors = Sponsor::active()->count();
        $expiringSoon = Sponsor::active()
            ->where('expires_at', '<=', now()->addDays(30)->toDateString())
            ->count();

        $sponsorsWithoutPayments = Sponsor::query()
            ->whereDoesntHave('platformIncome', function ($q) use ($from, $to) {
                $q->when($from, fn ($qq) => $qq->whereDate('income_date', '>=', $from))
                    ->when($to, fn ($qq) => $qq->whereDate('income_date', '<=', $to));
            })
            ->where('is_active', true)
            ->with('tier')
            ->orderBy('name')
            ->get();

        return view('reports.sponsorship', compact(
            'from', 'to', 'bySponsor', 'totalRevenue', 'totalLinkedRevenue', 'totalUnlinkedRevenue',
            'activeSponsors', 'expiringSoon', 'sponsorsWithoutPayments',
        ));
    }

    public function sponsorshipExport(Request $request): StreamedResponse
    {
        [$from, $to] = $this->parseDateRange($request);

        $items = PlatformIncome::query()
            ->where('category', 'sponsorship')
            ->with(['sponsor.tier'])
            ->when($from, fn ($q) => $q->whereDate('income_date', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('income_date', '<=', $to))
            ->orderByDesc('income_date')
            ->get();

        $filename = 'SAPRF_Sponsorship_Report_' . now()->format('Y-m-d') . '.csv';

        return response()->streamDownload(function () use ($items) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, ['Date', 'Sponsor', 'Tier', 'Description', 'Source', 'Amount (ZAR)', 'Reference']);

            foreach ($items as $item) {
                fputcsv($handle, [
                    $item->income_date->format('Y-m-d'),
                    $item->sponsor?->name ?? '',
                    $item->sponsor?->tier?->name ?? '',
                    $item->description,
                    $item->source ?? '',
                    number_format((float) $item->amount, 2, '.', ''),
                    $item->reference ?? '',
                ]);
            }

            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    // ──────────────────────────────────────────────────────────────────────
    // Selection Report
    // ──────────────────────────────────────────────────────────────────────

    public function selection(Request $request): View
    {
        $series = $request->input('series', 'PRS');
        $season = $request->input('season', (string) now()->year);

        // Pull all paid active members who shot at least one match this series/season
        $shooterIds = Score::query()
            ->where('status', 'valid')
            ->whereHas('match', function ($q) use ($series, $season) {
                $q->where('series', $series)
                    ->where(function ($q2) use ($season) {
                        $q2->where('season', $season)
                            ->orWhereRaw('YEAR(match_date) = ?', [$season]);
                    });
            })
            ->whereNotNull('user_id')
            ->distinct('user_id')
            ->pluck('user_id');

        $users = \App\Models\User::query()
            ->whereIn('id', $shooterIds)
            ->with(['province', 'membership' => fn ($q) => $q->latest()])
            ->orderBy('name')
            ->get();

        $rows = $users->map(function ($user) use ($series, $season) {
            $status = $this->qualificationService->getQualificationStatus($user, $series, $season);

            $standing = \App\Models\Standing::query()
                ->where('user_id', $user->id)
                ->where('series', $series)
                ->where('season', $season)
                ->whereNull('province_id')
                ->whereNull('division_id')
                ->first();

            return [
                'user' => $user,
                'province' => $user->province?->name,
                'membership_active' => $user->membership?->status === 'active' && $user->membership?->payment_status === 'paid',
                'saprf_number' => $user->membership?->saprf_number,
                'required' => $status['required'],
                'completed' => $status['completed'],
                'qualified' => $status['qualified'],
                'rank' => $standing?->rank,
                'points' => $standing?->points,
            ];
        })
            ->sortBy([
                ['qualified', 'desc'],
                ['rank', 'asc'],
            ])
            ->values();

        $seasons = collect(range(now()->year, now()->year - 4))->map(fn ($y) => (string) $y);

        $qualifiedCount = $rows->where('qualified', true)->count();

        return view('reports.selection', compact(
            'series', 'season', 'seasons', 'rows', 'qualifiedCount',
        ));
    }

    public function selectionExport(Request $request): StreamedResponse
    {
        $series = $request->input('series', 'PRS');
        $season = $request->input('season', (string) now()->year);

        $shooterIds = Score::query()
            ->where('status', 'valid')
            ->whereHas('match', function ($q) use ($series, $season) {
                $q->where('series', $series)
                    ->where(function ($q2) use ($season) {
                        $q2->where('season', $season)
                            ->orWhereRaw('YEAR(match_date) = ?', [$season]);
                    });
            })
            ->whereNotNull('user_id')
            ->distinct('user_id')
            ->pluck('user_id');

        $users = \App\Models\User::query()
            ->whereIn('id', $shooterIds)
            ->with(['province', 'membership' => fn ($q) => $q->latest()])
            ->orderBy('name')
            ->get();

        $filename = "SAPRF_Selection_Report_{$series}_{$season}.csv";

        return response()->streamDownload(function () use ($users, $series, $season) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, [
                'Rank', 'Name', 'SAPRF Number', 'Province', 'Membership',
                'Out-of-Province Required', 'Out-of-Province Completed', 'Qualified',
                'Season Points', 'Email',
            ]);

            $rows = $users->map(function ($user) use ($series, $season) {
                $status = $this->qualificationService->getQualificationStatus($user, $series, $season);

                $standing = \App\Models\Standing::query()
                    ->where('user_id', $user->id)
                    ->where('series', $series)
                    ->where('season', $season)
                    ->whereNull('province_id')
                    ->whereNull('division_id')
                    ->first();

                return [
                    'rank' => $standing?->rank,
                    'name' => $user->name,
                    'saprf_number' => $user->membership?->saprf_number,
                    'province' => $user->province?->name,
                    'membership' => ($user->membership?->status === 'active' && $user->membership?->payment_status === 'paid') ? 'Active' : ($user->membership?->status ?? 'None'),
                    'required' => $status['required'],
                    'completed' => $status['completed'],
                    'qualified' => $status['qualified'] ? 'Yes' : 'No',
                    'points' => $standing?->points,
                    'email' => $user->email,
                ];
            })
                ->sortBy([
                    ['qualified', 'desc'],
                    ['rank', 'asc'],
                ]);

            foreach ($rows as $r) {
                fputcsv($handle, [
                    $r['rank'] ?? '—',
                    $r['name'],
                    $r['saprf_number'] ?? '',
                    $r['province'] ?? '',
                    $r['membership'],
                    $r['required'],
                    $r['completed'],
                    $r['qualified'],
                    $r['points'] !== null ? number_format((float) $r['points'], 4, '.', '') : '',
                    $r['email'],
                ]);
            }

            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    // ──────────────────────────────────────────────────────────────────────
    // Participation Report
    // ──────────────────────────────────────────────────────────────────────

    public function participation(Request $request): View
    {
        $season = $request->input('season', (string) now()->year);
        $series = $request->input('series');

        $matchQuery = MatchEvent::query()
            ->where(function ($q) use ($season) {
                $q->where('season', $season)
                    ->orWhereRaw('YEAR(match_date) = ?', [$season]);
            })
            ->when($series, fn ($q) => $q->where('match_type', $series))
            ->with(['province', 'creator:id,name'])
            ->withCount(['registrations as confirmed_registrations' => function ($q) {
                $q->whereIn('registration_status', ['confirmed', 'pending']);
            }])
            ->withCount(['registrations as waitlisted_registrations' => function ($q) {
                $q->where('registration_status', 'waitlisted');
            }])
            ->withCount(['scores as valid_scores' => function ($q) {
                $q->where('status', 'valid');
            }])
            ->orderByDesc('match_date');

        $matches = $matchQuery->get();

        // Summary stats
        $totalMatches = $matches->count();
        $totalEntries = (int) $matches->sum('confirmed_registrations');
        $totalWaitlisted = (int) $matches->sum('waitlisted_registrations');
        $totalScores = (int) $matches->sum('valid_scores');
        $uniqueShooters = Score::query()
            ->whereHas('match', function ($q) use ($season, $series) {
                $q->where(function ($q2) use ($season) {
                    $q2->where('season', $season)
                        ->orWhereRaw('YEAR(match_date) = ?', [$season]);
                })
                    ->when($series, fn ($qq) => $qq->where('match_type', $series));
            })
            ->where('status', 'valid')
            ->whereNotNull('user_id')
            ->distinct('user_id')
            ->count('user_id');

        // Participation by province (counting completed matches' valid scores)
        $byProvince = Score::query()
            ->select('users.province_id')
            ->selectRaw('COUNT(DISTINCT scores.user_id) as shooter_count')
            ->selectRaw('COUNT(scores.id) as score_count')
            ->join('users', 'scores.user_id', '=', 'users.id')
            ->join('matches', 'scores.match_id', '=', 'matches.id')
            ->where('scores.status', 'valid')
            ->where(function ($q) use ($season) {
                $q->where('matches.season', $season)
                    ->orWhereRaw('YEAR(matches.match_date) = ?', [$season]);
            })
            ->when($series, fn ($q) => $q->where('matches.match_type', $series))
            ->groupBy('users.province_id')
            ->orderByDesc('shooter_count')
            ->get();

        $byProvince = $byProvince->map(function ($row) {
            $row->province_name = \App\Models\Province::find($row->province_id)?->name ?? 'Unknown';

            return $row;
        });

        $seasons = collect(range(now()->year, now()->year - 4))->map(fn ($y) => (string) $y);

        return view('reports.participation', compact(
            'season', 'series', 'seasons', 'matches',
            'totalMatches', 'totalEntries', 'totalWaitlisted', 'totalScores', 'uniqueShooters',
            'byProvince',
        ));
    }

    public function participationExport(Request $request): StreamedResponse
    {
        $season = $request->input('season', (string) now()->year);
        $series = $request->input('series');

        $matches = MatchEvent::query()
            ->where(function ($q) use ($season) {
                $q->where('season', $season)
                    ->orWhereRaw('YEAR(match_date) = ?', [$season]);
            })
            ->when($series, fn ($q) => $q->where('match_type', $series))
            ->with(['province'])
            ->withCount(['registrations as confirmed_registrations' => function ($q) {
                $q->whereIn('registration_status', ['confirmed', 'pending']);
            }])
            ->withCount(['registrations as waitlisted_registrations' => function ($q) {
                $q->where('registration_status', 'waitlisted');
            }])
            ->withCount(['scores as valid_scores' => function ($q) {
                $q->where('status', 'valid');
            }])
            ->orderByDesc('match_date')
            ->get();

        $filename = "SAPRF_Participation_Report_{$season}" . ($series ? "_{$series}" : '') . '.csv';

        return response()->streamDownload(function () use ($matches) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, [
                'Match Date', 'Match Name', 'Series', 'Level', 'Province', 'Status',
                'Confirmed Entries', 'Waitlisted Entries', 'Valid Scores', 'Capacity',
            ]);

            foreach ($matches as $m) {
                fputcsv($handle, [
                    $m->match_date->format('Y-m-d'),
                    $m->name,
                    $m->match_type,
                    $m->series_level,
                    $m->province?->name ?? '',
                    $m->status,
                    $m->confirmed_registrations,
                    $m->waitlisted_registrations,
                    $m->valid_scores,
                    $m->max_competitors ?? '',
                ]);
            }

            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    // ──────────────────────────────────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────────────────────────────────

    private function parseDateRange(Request $request): array
    {
        $from = $request->filled('from') ? \Carbon\Carbon::parse($request->input('from'))->toDateString() : null;
        $to = $request->filled('to') ? \Carbon\Carbon::parse($request->input('to'))->toDateString() : null;

        if ($request->input('period') === 'this_year') {
            $from = now()->startOfYear()->toDateString();
            $to = now()->endOfYear()->toDateString();
        } elseif ($request->input('period') === 'last_year') {
            $from = now()->subYear()->startOfYear()->toDateString();
            $to = now()->subYear()->endOfYear()->toDateString();
        } elseif ($request->input('period') === 'last_30') {
            $from = now()->subDays(30)->toDateString();
            $to = now()->toDateString();
        }

        return [$from, $to];
    }
}
