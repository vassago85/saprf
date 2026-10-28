<?php

namespace App\Http\Controllers;

use App\Enums\AnnouncementCategory;
use App\Models\Announcement;
use App\Models\AuditLog;
use App\Jobs\NotifyScoresFinalizedJob;
use App\Models\MatchEvent;
use App\Models\MatchRegistration;
use App\Models\Payout;
use App\Models\Province;
use App\Models\Score;
use App\Models\ScoreImport;
use App\Models\Venue;
use App\Services\FinancialService;
use App\Services\MatchFinanceService;
use App\Services\PaymentInquiryService;
use App\Services\SettingsService;
use App\Services\StandingsSnapshotRecorder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * One match. One URL per tab. One controller.
 *
 * This replaces the pattern where "manage a match" meant hopping between
 * `matches.show`, `matches.edit`, `registrations.index?match_id=…`,
 * `score-imports.index?match_id=…`, `scores.index`, `financials.match-report`
 * and `matches.announcements.create`. Everything the MD does for a single
 * match now lives on one of six tabs inside this workspace.
 *
 * Every tab shares the same chrome partial — header + stage bar + next
 * action banner + tab list — so navigation feels like a single page even
 * though each tab is a real deep-linkable route.
 */
class MatchWorkspaceController extends Controller
{
    public function __construct(
        private readonly MatchFinanceService $matchFinance,
        private readonly FinancialService $financials,
        private readonly SettingsService $settings,
    ) {}

    // ── Overview ──

    public function overview(Request $request, MatchEvent $match): View
    {
        $this->authorize('view', $match);

        $match->load(['province', 'creator']);

        $counts = $this->matchFinance->entryCounts($match);
        $money = $this->matchFinance->matchMoney($match);
        $resultsPublished = $match->scores()->exists();
        $scoresCount = $match->scores()->count();
        $payout = $match->payouts()->where('payee_type', 'match_director')->latest('id')->first();

        $recentActivity = AuditLog::query()
            ->where(function ($query) use ($match) {
                $query->where(function ($q) use ($match) {
                    $q->where('entity_type', 'MatchEvent')
                        ->where('entity_id', $match->id);
                })->orWhere(function ($q) use ($match) {
                    $q->where('entity_type', 'match')
                        ->where('entity_id', $match->id);
                })->orWhere(function ($q) use ($match) {
                    $q->where('entity_type', 'MatchAnnouncement')
                        ->whereIn('entity_id', $match->announcements()->pluck('id'));
                })->orWhere(function ($q) use ($match) {
                    $q->where('entity_type', 'payout')
                        ->whereIn('entity_id', $match->payouts()->pluck('id'));
                });
            })
            ->with('user:id,name')
            ->latest('created_at')
            ->limit(6)
            ->get();

        return view('matches.workspace.overview', $this->workspaceData($match, 'overview', [
            'counts' => $counts,
            'money' => $money,
            'resultsPublished' => $resultsPublished,
            'scoresCount' => $scoresCount,
            'payout' => $payout,
            'recentActivity' => $recentActivity,
        ]));
    }

    // ── Entries ──

    public function entries(Request $request, MatchEvent $match): View
    {
        $this->authorize('view', $match);

        $counts = $this->matchFinance->entryCounts($match);

        $query = MatchRegistration::query()
            ->with(['user:id,name,email', 'division:id,name'])
            ->where('match_id', $match->id)
            ->orderByRaw('CASE registration_status
                WHEN "pending" THEN 1
                WHEN "confirmed" THEN 2
                WHEN "waitlisted" THEN 3
                WHEN "cancelled" THEN 4
                ELSE 5 END')
            ->orderBy('registered_at');

        if ($status = $request->input('status')) {
            if ($status === 'paid') {
                $query->where('payment_status', 'paid');
            } elseif ($status === 'pending') {
                $query->whereNotIn('payment_status', ['paid', 'waived'])
                    ->where('fee_amount', '>', 0);
            } elseif ($status === 'waitlist') {
                $query->where('registration_status', 'waitlisted');
            } elseif ($status === 'cancelled') {
                $query->where('registration_status', 'cancelled');
            }
        }

        if ($category = $request->input('category')) {
            $query->where('membership_fee_category', $category);
        }

        if ($search = trim((string) $request->input('search', ''))) {
            $query->where(function ($q) use ($search) {
                $q->where('shooter_name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
        }

        $entries = $query->paginate(30)->withQueryString();

        return view('matches.workspace.entries', $this->workspaceData($match, 'entries', [
            'entries' => $entries,
            'counts' => $counts,
            'filters' => [
                'status' => $request->input('status'),
                'category' => $request->input('category'),
                'search' => $request->input('search'),
            ],
        ]));
    }

    /**
     * Email every shooter on this match who still owes an entry fee.
     * Same message as the per-row inquiry; the 24-hour cooldown still applies.
     */
    public function notifyUnpaid(Request $request, MatchEvent $match, PaymentInquiryService $inquiries): RedirectResponse
    {
        $this->authorize('update', $match);

        $registrations = MatchRegistration::query()
            ->with('user')
            ->where('match_id', $match->id)
            ->where('registration_status', '!=', 'cancelled')
            ->get();

        return back()->with('success', $inquiries->sendOutstanding($request->user(), $registrations));
    }

    // ── Scores ──

    public function scores(Request $request, MatchEvent $match): View
    {
        $this->authorize('view', $match);

        $imports = ScoreImport::query()
            ->where('match_id', $match->id)
            ->with('uploader:id,name')
            ->withCount('scores')
            ->latest('created_at')
            ->get();

        $scoresCount = Score::query()->where('match_id', $match->id)->count();
        $hasAnyScores = $scoresCount > 0;

        $counts = $this->matchFinance->entryCounts($match);

        $checklist = [
            'entries_exported' => $counts['counted'] > 0,
            'scores_imported' => $imports->isNotEmpty(),
            'all_shooters_matched' => $hasAnyScores && Score::query()
                ->where('match_id', $match->id)
                ->whereNull('user_id')
                ->doesntExist(),
            'results_published' => $hasAnyScores,
            'scores_finalized' => $match->scoresAreFinalized(),
        ];

        return view('matches.workspace.scores', $this->workspaceData($match, 'scores', [
            'imports' => $imports,
            'scoresCount' => $scoresCount,
            'hasAnyScores' => $hasAnyScores,
            'counts' => $counts,
            'checklist' => $checklist,
        ]));
    }

    /**
     * Mark the scores on this match as complete — this is the MD's explicit
     * "yes, I have checked the names and provisional results, send the
     * notifications now" button on the scores tab. Deliberately separate
     * from `matches.status = completed` (which can flip automatically on
     * import / payout) and from "results published" (which happens the
     * moment any score row exists).
     *
     * We guard behind `update` (owning MD / staff). The notify job is only
     * dispatched if we haven't already fanned out for this match — if the
     * MD unmarks and re-marks (e.g. to fix a typo), re-finalising keeps
     * the fresh timestamp but will NOT re-blast the audience.
     */
    public function completeScores(Request $request, MatchEvent $match): RedirectResponse
    {
        $this->authorize('update', $match);

        if (! $match->scores()->exists()) {
            return redirect()
                ->route('matches.scores', $match)
                ->with('error', 'Import or enter scores before marking the match complete.');
        }

        $wasFinalized = $match->scoresAreFinalized();

        $match->forceFill([
            'scores_finalized_at' => now(),
            'scores_finalized_by' => $request->user()->id,
        ])->save();

        // Snapshot the current standings for every shooter in this series/
        // season so the public standings page can draw movement arrows
        // (▲n/▼n) against the previous finalisation. Deliberately BEFORE
        // the notification dispatch — notifications are user-visible and
        // allowed to fail; the snapshot is a tiny insert and should always
        // succeed. StandingsSnapshotRecorder is idempotent on taken_at,
        // so a quick unmark→remark won't double-write.
        app(StandingsSnapshotRecorder::class)->recordForMatch($match->fresh());

        if (! $wasFinalized && $match->scores_finalized_notified_at === null) {
            NotifyScoresFinalizedJob::dispatch($match->id);
            $flash = 'Scores marked complete. Shooters who opted in will be notified.';
        } else {
            $flash = 'Scores marked complete. Notifications were already sent earlier — not re-sent.';
        }

        return redirect()
            ->route('matches.scores', $match)
            ->with('success', $flash);
    }

    /**
     * Clears the finalized marker so the MD can keep editing. We leave
     * `scores_finalized_notified_at` alone on purpose — see
     * `completeScores()`.
     */
    public function uncompleteScores(Request $request, MatchEvent $match): RedirectResponse
    {
        $this->authorize('update', $match);

        $match->forceFill([
            'scores_finalized_at' => null,
            'scores_finalized_by' => null,
        ])->save();

        return redirect()
            ->route('matches.scores', $match)
            ->with('success', 'Scores unmarked. Adjust and mark complete again when ready.');
    }

    // ── Finances ──

    public function finances(Request $request, MatchEvent $match): View
    {
        $this->authorize('view', $match);

        $user = $request->user();
        $viewerIsMatchDirector = $user->id === $match->created_by;

        $match->load(['registrations.user:id,name', 'registrations.division:id,name', 'expenses.creator:id,name', 'user:id,name']);

        $money = $this->matchFinance->matchMoney($match);
        $counts = $this->matchFinance->entryCounts($match);

        $payout = Payout::query()
            ->where('match_id', $match->id)
            ->where('payee_type', 'match_director')
            ->latest('id')
            ->first();

        // Label the line from the surcharge amounts stored on paid rows.
        // The global rate and a headcount of non-member categories can
        // disagree with that sum (lapsed surcharges, older rates, zeros).
        $surchargeRows = $money['paid_entries']->filter(
            fn ($reg) => (float) $reg->surcharge_amount > 0
        );
        $surchargeRates = $surchargeRows
            ->map(fn ($reg) => number_format((float) $reg->surcharge_amount, 2, '.', ''))
            ->unique()
            ->values();

        return view('matches.workspace.finances', $this->workspaceData($match, 'finances', [
            'money' => $money,
            'counts' => $counts,
            'payout' => $payout,
            'viewerIsMatchDirector' => $viewerIsMatchDirector,
            'expenses' => $match->expenses->sortByDesc('created_at'),
            'surchargedEntryCount' => $surchargeRows->count(),
            'uniformSurchargeRate' => $surchargeRates->count() === 1 ? (float) $surchargeRates->first() : null,
        ]));
    }

    /**
     * The cost estimator lives on the Finances tab. Saving here only
     * updates the planning headcount — entry fees stay on Settings.
     */
    public function updateEstimatedShooters(Request $request, MatchEvent $match): RedirectResponse
    {
        $this->authorize('update', $match);

        $validated = $request->validate([
            'estimated_shooters' => ['required', 'integer', 'min:1', 'max:999'],
        ]);

        $match->update([
            'estimated_shooters' => $validated['estimated_shooters'],
        ]);

        return redirect()
            ->route('matches.finances', $match)
            ->with('success', 'Expected shooter count saved.');
    }

    // ── Messages ──

    public function messages(Request $request, MatchEvent $match): View
    {
        $this->authorize('view', $match);

        $counts = $this->matchFinance->entryCounts($match);

        // Match bulletins now live in the unified `announcements` table
        // (see MatchAnnouncementController). The legacy
        // `MatchEvent::announcements()` relation still points at the
        // older `match_announcements` table — we deliberately bypass
        // it here so recent sends from the current composer surface.
        $announcements = Announcement::query()
            ->with('creator:id,name')
            ->where('match_id', $match->id)
            ->where('category', AnnouncementCategory::MatchBulletin->value)
            ->orderByDesc('sent_at')
            ->orderByDesc('created_at')
            ->get();

        return view('matches.workspace.messages', $this->workspaceData($match, 'messages', [
            'counts' => $counts,
            'announcements' => $announcements,
        ]));
    }

    // ── Settings ──

    public function settings(Request $request, MatchEvent $match): View
    {
        $this->authorize('update', $match);

        $match->load(['divisions', 'province', 'creator']);

        $counts = $this->matchFinance->entryCounts($match);

        $provinces = Province::query()->orderBy('name')->get();
        $venues = Venue::query()->active()->with('province')->orderBy('name')->get();
        $settings = $this->settings->all();

        $selfEntry = $match->userRegistration($request->user());
        $availableDivisionIds = $match->availableDivisions()->pluck('id');
        $selfDefaultDivisionId = $request->user()->matchRegistrations()
            ->whereIn('division_id', $availableDivisionIds)
            ->latest('registered_at')
            ->value('division_id') ?? $availableDivisionIds->first();

        return view('matches.workspace.settings', $this->workspaceData($match, 'settings', [
            'counts' => $counts,
            'lockedByPaidEntries' => $counts['paid'] > 0,
            'provinces' => $provinces,
            'venues' => $venues,
            'settings' => $settings,
            'selfEntry' => $selfEntry,
            'selfDefaultDivisionId' => $selfDefaultDivisionId,
        ]));
    }

    // ── Shared workspace data for the chrome partial ──

    /**
     * Common view data every tab needs: the match itself, which tab is
     * active, counts (so the tab bar can show "Entries 43"), and the
     * stage context (steps + next-action banner).
     *
     * @param  array<string, mixed>  $extra  tab-specific view data
     * @return array<string, mixed>
     */
    private function workspaceData(MatchEvent $match, string $activeTab, array $extra = []): array
    {
        $counts = $extra['counts'] ?? $this->matchFinance->entryCounts($match);

        return array_merge([
            'match' => $match,
            'activeTab' => $activeTab,
            'stageContext' => $match->stageContext(),
            'counts' => $counts,
            'tabs' => $this->tabsFor($match, $counts),
        ], $extra);
    }

    /**
     * @param  array<string, int>  $counts
     * @return list<array{key: string, label: string, route: string, count: ?int}>
     */
    private function tabsFor(MatchEvent $match, array $counts): array
    {
        return [
            ['key' => 'overview', 'label' => 'Overview', 'route' => 'matches.overview', 'count' => null],
            ['key' => 'entries', 'label' => 'Entries', 'route' => 'matches.entries', 'count' => $counts['counted'] ?? null],
            ['key' => 'scores', 'label' => 'Scores', 'route' => 'matches.scores', 'count' => null],
            ['key' => 'finances', 'label' => 'Finances', 'route' => 'matches.finances', 'count' => null],
            ['key' => 'messages', 'label' => 'Messages', 'route' => 'matches.messages', 'count' => null],
            ['key' => 'settings', 'label' => 'Settings', 'route' => 'matches.settings', 'count' => null],
        ];
    }
}
