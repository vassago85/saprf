<?php

namespace App\Http\Controllers;

use App\Models\Division;
use App\Models\FederationColourOverride;
use App\Models\MatchEvent;
use App\Models\PlatformIncome;
use App\Models\Province;
use App\Models\Score;
use App\Models\Sponsor;
use App\Models\Standing;
use App\Services\FederationColoursReport;
use App\Services\QualificationService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportsController extends Controller
{
    public function __construct(
        private readonly QualificationService $qualificationService,
        private readonly FederationColoursReport $federationColours,
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
                            ->orWhereYear('match_date', $season);
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

        $standings = $this->resolveSelectionStandings($users->pluck('id'), $series, $season);

        $rows = $users->map(function ($user) use ($series, $season, $standings) {
            $status = $this->qualificationService->getQualificationStatus($user, $series, $season);

            $standing = $standings->get($user->id);

            // "x of 0 out-of-province" reads as a failing status even when
            // the rule explicitly waives the OOP requirement. Flip the
            // label so it says "No out-of-province requirement" instead.
            $progress = $status['required'] === 0
                ? 'No out-of-province requirement'
                : "{$status['completed']} of {$status['required']} out-of-province";

            return [
                'user' => $user,
                'province' => $user->province?->name,
                'membership_active' => $user->membershipStatus() === \App\Enums\MembershipStatus::Active,
                'membership_label' => $user->membershipStatus()?->label() ?? 'No membership',
                'saprf_number' => $user->membership?->saprf_number,
                'required' => $status['required'],
                'completed' => $status['completed'],
                'qualified' => $status['qualified'],
                'progress' => $progress,
                'rank' => $standing?->rank,
                'points' => $standing?->points,
                'rank_division' => $standing?->division_id ? $standing->division?->name : null,
            ];
        })
            // Qualified first, then by rank ascending (nulls last). Done as
            // an explicit comparator so the ordering is predictable — the
            // array form of sortBy() with a callback key has quirks
            // between Laravel minor versions.
            ->sort(function (array $a, array $b): int {
                if ($a['qualified'] !== $b['qualified']) {
                    return $a['qualified'] ? -1 : 1;
                }

                $rankA = $a['rank'] ?? PHP_INT_MAX;
                $rankB = $b['rank'] ?? PHP_INT_MAX;

                return $rankA <=> $rankB;
            })
            ->values();

        $seasons = collect(range(now()->year, now()->year - 4))->map(fn ($y) => (string) $y);

        $qualifiedCount = $rows->where('qualified', true)->count();

        return view('reports.selection', compact(
            'series', 'season', 'seasons', 'rows', 'qualifiedCount',
        ));
    }

    /**
     * Pick one national standing per shooter for the selection report.
     *
     * Standings are persisted per division (plus an overall row with a null
     * division when the scoring mode produces one), so a null-division lookup
     * alone leaves most shooters unranked. Prefer the overall standing; if it
     * does not exist, fall back to the shooter's best division standing
     * (best rank, then highest points).
     *
     * @param  Collection<int, int>  $userIds
     * @return Collection<int, Standing> keyed by user id
     */
    private function resolveSelectionStandings(Collection $userIds, string $series, string $season): Collection
    {
        return Standing::query()
            ->whereIn('user_id', $userIds)
            ->where('series', $series)
            ->where('season', $season)
            ->whereNull('province_id')
            ->with('division:id,name')
            ->get()
            ->groupBy('user_id')
            ->map(function (Collection $standings) {
                $overall = $standings->first(fn (Standing $s) => $s->division_id === null);

                return $overall ?? $standings
                    ->sort(fn (Standing $a, Standing $b) => [$a->rank ?? PHP_INT_MAX, -(float) $a->points]
                        <=> [$b->rank ?? PHP_INT_MAX, -(float) $b->points])
                    ->first();
            });
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
                            ->orWhereYear('match_date', $season);
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

        $standings = $this->resolveSelectionStandings($users->pluck('id'), $series, $season);

        return response()->streamDownload(function () use ($users, $series, $season, $standings) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, [
                'Rank', 'Name', 'SAPRF Number', 'Province', 'Membership',
                'Out-of-Province Required', 'Out-of-Province Completed', 'Qualified',
                'Season Points', 'Email',
            ]);

            $rows = $users->map(function ($user) use ($series, $season, $standings) {
                $status = $this->qualificationService->getQualificationStatus($user, $series, $season);

                $standing = $standings->get($user->id);

                return [
                    'rank' => $standing?->rank,
                    'name' => $user->name,
                    'saprf_number' => $user->membership?->saprf_number,
                    'province' => $user->province?->name,
                    'membership' => $user->membershipStatus()?->label() ?? 'No membership',
                    'required' => $status['required'],
                    'completed' => $status['completed'],
                    'qualified' => $status['qualified'] ? 'Yes' : 'No',
                    'points' => $standing?->points,
                    'email' => $user->email,
                ];
            })
                ->sortBy([
                    ['qualified', 'desc'],
                    [fn (array $row) => $row['rank'] ?? PHP_INT_MAX, 'asc'],
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
    // Federation colours
    // ──────────────────────────────────────────────────────────────────────

    public function federationColours(Request $request): View
    {
        [$series, $season, $seasons, $report, $filters, $catalog, $thresholdError] = $this->coloursReport($request);

        return view('reports.federation-colours', compact(
            'series', 'season', 'seasons', 'report', 'filters', 'catalog', 'thresholdError',
        ));
    }

    public function federationColoursCsv(Request $request): StreamedResponse
    {
        $settings = $this->coloursSettings($request);
        $rows = $this->federationColours->flat($settings['series'], $settings['season'], $settings['options']);
        $filename = "SAPRF-Federation-Colours-{$settings['series']}-{$settings['season']}.csv";

        return response()->streamDownload(function () use ($rows) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, [
                'Series', 'Season', 'Division', 'Province', 'Rank', 'Shooter',
                'Member Number', 'Points', 'Matches Counted', 'Percentage',
                'SA Championships', 'Override', 'Grade',
            ]);

            foreach ($rows as $row) {
                fputcsv($handle, [
                    $row['series'],
                    $row['season'],
                    $row['division'],
                    $row['province'],
                    $row['rank'],
                    $row['name'],
                    $row['saprf_number'] ?? '',
                    number_format($row['points'], 2, '.', ''),
                    $row['matches_counted'] ?? '',
                    $row['percentage'] !== null ? number_format($row['percentage'], 2, '.', '') : '',
                    $row['shot_champs'] ? 'Yes' : 'No',
                    $row['override_reason'] ?? '',
                    $row['grade'] ?? '',
                ]);
            }

            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    public function federationColoursPdf(Request $request): Response
    {
        [$series, $season, , $report, $filters] = $this->coloursReport($request);
        $seriesLabel = $series === 'PR22' ? 'PR22' : 'Centrefire';

        return Pdf::loadView('reports.federation-colours-pdf', [
            'series' => $series,
            'seriesLabel' => $seriesLabel,
            'season' => $season,
            'report' => $report,
            'filters' => $filters,
            'generatedAt' => now()->format('d M Y H:i'),
        ])->setPaper('a4', 'landscape')
            ->download("SAPRF-Federation-Colours-{$series}-{$season}.pdf");
    }

    public function storeFederationColourOverride(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'series' => ['required', Rule::in(FederationColoursReport::SERIES)],
            'season' => ['required', 'string', 'max:10'],
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'division_id' => ['required', 'integer', 'exists:divisions,id'],
            'reason' => ['required', 'string', 'min:8', 'max:500'],
        ]);

        $hasStanding = Standing::query()
            ->where('user_id', $validated['user_id'])
            ->where('series', $validated['series'])
            ->where('season', $validated['season'])
            ->where('division_id', $validated['division_id'])
            ->whereNotNull('province_id')
            ->exists();

        if (! $hasStanding) {
            throw ValidationException::withMessages([
                'reason' => 'That shooter has no provincial standing in this division.',
            ]);
        }

        FederationColourOverride::query()->updateOrCreate(
            [
                'user_id' => $validated['user_id'],
                'series' => $validated['series'],
                'season' => $validated['season'],
                'division_id' => $validated['division_id'],
            ],
            [
                'reason' => trim($validated['reason']),
                'created_by' => $request->user()?->id,
            ],
        );

        return redirect()->route('reports.federation-colours', $this->coloursReturnQuery($request))
            ->with('success', 'Override saved. The grade will be awarded with the reason on the report.');
    }

    public function destroyFederationColourOverride(Request $request, FederationColourOverride $override): RedirectResponse
    {
        $override->delete();

        return redirect()->route('reports.federation-colours', $this->coloursReturnQuery($request))
            ->with('success', 'Override removed.');
    }

    /**
     * @return array{0: string, 1: string, 2: Collection<int, string>, 3: array<string, mixed>, 4: array<string, mixed>, 5: array<string, mixed>, 6: string|null}
     */
    private function coloursReport(Request $request): array
    {
        $settings = $this->coloursSettings($request);
        $seasons = collect(range(now()->year, now()->year - 4))->map(fn ($y) => (string) $y);
        $report = $this->federationColours->build($settings['series'], $settings['season'], $settings['options']);
        $catalog = $this->federationColours->catalog($settings['series'], $settings['season']);

        return [
            $settings['series'],
            $settings['season'],
            $seasons,
            $report,
            $settings['filters'],
            $catalog,
            $settings['threshold_error'],
        ];
    }

    /**
     * @return array{
     *     series: string,
     *     season: string,
     *     options: array<string, mixed>,
     *     filters: array<string, mixed>,
     *     threshold_error: string|null
     * }
     */
    private function coloursSettings(Request $request): array
    {
        $series = strtoupper((string) $request->input('series', 'PR22'));
        if (! in_array($series, FederationColoursReport::SERIES, true)) {
            $series = 'PR22';
        }

        $season = (string) $request->input('season', now()->year);
        $thresholds = $this->colourThresholds($request);
        $divisionId = $this->existingId($request->input('division'), Division::class);
        $provinceId = $this->existingId($request->input('province'), Province::class);
        $requireChamps = $request->boolean('require_champs');
        $topN = $this->parseTopN($request->input('top_n'));

        $options = [
            'division_id' => $divisionId,
            'province_id' => $provinceId,
            'require_champs' => $requireChamps,
            'gold' => $thresholds['gold'],
            'silver' => $thresholds['silver'],
            'bronze' => $thresholds['bronze'],
            'top_n' => $topN,
        ];

        $filters = [
            'series' => $series,
            'season' => $season,
            'division' => $divisionId,
            'province' => $provinceId,
            'require_champs' => $requireChamps ? '1' : '0',
            'gold' => $thresholds['gold'],
            'silver' => $thresholds['silver'],
            'bronze' => $thresholds['bronze'],
            'top_n' => $topN,
        ];

        return [
            'series' => $series,
            'season' => $season,
            'options' => $options,
            'filters' => $filters,
            'threshold_error' => $thresholds['error'],
        ];
    }

    /**
     * Whitelist of allowed top-N values for the colours report. Anything
     * outside this set resolves to null (no trimming) rather than erroring,
     * so a stale bookmark with an odd value still renders the full report.
     */
    private function parseTopN(mixed $value): ?int
    {
        if ($value === null || $value === '' || $value === 'all') {
            return null;
        }

        $n = (int) $value;

        return in_array($n, [3, 5, 10, 15, 20, 25, 50], true) ? $n : null;
    }

    /**
     * @return array{gold: float, silver: float, bronze: float, error: string|null}
     */
    private function colourThresholds(Request $request): array
    {
        $defaults = ['gold' => 95.0, 'silver' => 85.0, 'bronze' => 80.0, 'error' => null];

        if (! $request->hasAny(['gold', 'silver', 'bronze'])) {
            return $defaults;
        }

        $validator = validator($request->only(['gold', 'silver', 'bronze']), [
            'gold' => ['required', 'numeric', 'min:0', 'max:100'],
            'silver' => ['required', 'numeric', 'min:0', 'max:100', 'lt:gold'],
            'bronze' => ['required', 'numeric', 'min:0', 'max:100', 'lt:silver'],
        ]);

        if ($validator->fails()) {
            return [
                'gold' => 95.0,
                'silver' => 85.0,
                'bronze' => 80.0,
                'error' => 'Gold must sit above Silver, and Silver above Bronze. The report is using 95, 85, and 80.',
            ];
        }

        $valid = $validator->validated();

        return [
            'gold' => round((float) $valid['gold'], 2),
            'silver' => round((float) $valid['silver'], 2),
            'bronze' => round((float) $valid['bronze'], 2),
            'error' => null,
        ];
    }

    /**
     * @param  class-string<\Illuminate\Database\Eloquent\Model>  $model
     */
    private function existingId(mixed $value, string $model): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        $id = (int) $value;

        return $id > 0 && $model::query()->whereKey($id)->exists() ? $id : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function coloursReturnQuery(Request $request): array
    {
        return array_filter(
            $request->only(['series', 'season', 'division', 'province', 'require_champs', 'gold', 'silver', 'bronze', 'top_n']),
            fn ($value) => $value !== null && $value !== '',
        );
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
