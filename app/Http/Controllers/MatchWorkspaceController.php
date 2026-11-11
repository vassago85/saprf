<?php

namespace App\Http\Controllers;

use App\Enums\AnnouncementCategory;
use App\Jobs\NotifyScoresFinalizedJob;
use App\Models\Announcement;
use App\Models\AuditLog;
use App\Models\MatchEvent;
use App\Models\MatchRegistration;
use App\Models\Payout;
use App\Models\Province;
use App\Models\Score;
use App\Models\ScoreImport;
use App\Models\Venue;
use App\Services\AuditLogService;
use App\Services\FinancialService;
use App\Services\ImpactScoring\ImpactLiveSyncService;
use App\Services\ImpactScoring\ImpactShooterExport;
use App\Services\MatchCloseoutWizard;
use App\Services\MatchFinanceService;
use App\Services\MatchWhatsappInviteMailer;
use App\Services\PaymentInquiryService;
use App\Services\RegistrationPricingService;
use App\Services\SettingsService;
use App\Services\StandingsCalculationService;
use App\Services\StandingsSnapshotRecorder;
use App\Support\ScoreFormat;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
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
        private readonly MatchCloseoutWizard $wizard,
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
            ->with([
                'user:id,name,email,phone,parent_id,is_managed_account',
                'user.parent:id,phone',
                'division:id,name',
            ])
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

        $showLiveBoard = $match->impact_live_sync
            && $match->impact_match_id
            && ! $match->scoresAreFinalized()
            && $match->impact_live_synced_at !== null;

        $scoresByUser = collect();
        $liveBoard = null;
        if ($showLiveBoard) {
            $scoresByUser = Score::query()
                ->where('match_id', $match->id)
                ->whereNotNull('user_id')
                ->get(['user_id', 'raw_score', 'raw_meta'])
                ->keyBy('user_id')
                ->map(function (Score $score) use ($match) {
                    $done = $score->raw_meta['impact']['stages_completed'] ?? null;
                    $total = $score->raw_meta['impact']['stages_total'] ?? null;
                    $stages = is_numeric($done)
                        ? ((int) $done).(is_numeric($total) ? ' / '.(int) $total : '')
                        : null;

                    return [
                        'score' => ScoreFormat::format($score->raw_score, $match->match_type, $match->series),
                        'stages' => $stages,
                    ];
                });

            $scoringIds = $scoresByUser->keys()->map(fn ($id) => (int) $id)->all();
            $activeEntries = MatchRegistration::query()
                ->where('match_id', $match->id)
                ->where('registration_status', '!=', 'cancelled')
                ->get(['id', 'user_id', 'shooter_name']);
            $missing = $activeEntries->filter(
                fn (MatchRegistration $registration) => ! $registration->user_id || ! in_array((int) $registration->user_id, $scoringIds, true)
            );

            if ($board = $request->input('board')) {
                if ($board === 'showing') {
                    $scoringIds === []
                        ? $query->whereRaw('0 = 1')
                        : $query->whereIn('user_id', $scoringIds);
                } elseif ($board === 'missing') {
                    $query->where('registration_status', '!=', 'cancelled');
                    if ($scoringIds !== []) {
                        $query->where(function ($inner) use ($scoringIds) {
                            $inner->whereNull('user_id')->orWhereNotIn('user_id', $scoringIds);
                        });
                    }
                }
            }

            $nameMap = is_array($match->impact_live_name_map) ? $match->impact_live_name_map : [];
            // Only entries that are not currently on the board are offered —
            // the MD is attaching an Impact name to someone who is missing.
            $assignableEntries = $missing
                ->filter(fn (MatchRegistration $registration) => (bool) $registration->user_id)
                ->map(fn (MatchRegistration $registration) => [
                    'id' => $registration->id,
                    'label' => $registration->shooter_name ?: '—',
                ])
                ->sortBy('label')
                ->values();

            $unmatched = collect($match->impact_live_unmatched ?? [])
                ->filter()
                ->values()
                ->map(fn (string $impactName) => [
                    'name' => $impactName,
                    'mapped_registration_id' => $nameMap[$impactName] ?? null,
                ])
                ->all();

            $liveBoard = [
                'showing' => $activeEntries->count() - $missing->count(),
                'missing' => $missing->count(),
                'missing_names' => $missing->pluck('shooter_name')->filter()->values()->all(),
                'unmatched' => $unmatched,
                'assignable_entries' => $assignableEntries,
            ];
        }

        $entries = $query->paginate(30)->withQueryString();

        $impactExportOmitted = $request->user()?->can('update', $match)
            ? app(ImpactShooterExport::class)->build($match)['omitted']
            : [];

        // The add-a-shooter panel (now rendered on this tab, not Settings)
        // relies on `$selfEntry` + `$selfDefaultDivisionId`. We compute
        // them the same way `settings()` does so the Alpine search +
        // "Add me" shortcut both work from here.
        $selfEntry = $match->userRegistration($request->user());
        $availableDivisionIds = $match->availableDivisions()->pluck('id');
        $selfDefaultDivisionId = $request->user()->matchRegistrations()
            ->whereIn('division_id', $availableDivisionIds)
            ->latest('registered_at')
            ->value('division_id') ?? $availableDivisionIds->first();

        return view('matches.workspace.entries', $this->workspaceData($match, 'entries', [
            'entries' => $entries,
            'counts' => $counts,
            'selfEntry' => $selfEntry,
            'selfDefaultDivisionId' => $selfDefaultDivisionId,
            'filters' => [
                'status' => $request->input('status'),
                'category' => $request->input('category'),
                'search' => $request->input('search'),
                'board' => $request->input('board'),
            ],
            'showLiveBoard' => $showLiveBoard,
            'scoresByUser' => $scoresByUser,
            'liveBoard' => $liveBoard,
            'impactExportOmitted' => $impactExportOmitted,
        ]));
    }

    /**
     * MD assigns an Impact score-sheet name that does not exactly match an
     * entry (nickname, different surname) to one of this match's entries.
     * Stored on the match so the next live pull picks the shooter up.
     */
    public function mapImpactName(Request $request, MatchEvent $match, ImpactLiveSyncService $sync): RedirectResponse
    {
        $this->authorize('update', $match);

        $data = $request->validate([
            'impact_name' => ['required', 'string', 'max:200'],
            'registration_id' => ['nullable', 'integer'],
        ]);

        $impactName = trim($data['impact_name']);
        $map = is_array($match->impact_live_name_map) ? $match->impact_live_name_map : [];
        $registrationId = (int) ($data['registration_id'] ?? 0);

        if ($registrationId > 0) {
            $exists = MatchRegistration::query()
                ->where('id', $registrationId)
                ->where('match_id', $match->id)
                ->where('registration_status', '!=', 'cancelled')
                ->exists();

            if (! $exists) {
                return back()->with('error', 'That entry does not belong to this match.');
            }

            $map[$impactName] = $registrationId;
        } else {
            unset($map[$impactName]);
        }

        $match->forceFill(['impact_live_name_map' => $map])->save();

        try {
            $sync->sync($match->fresh());
        } catch (\Throwable) {
            // The scheduler will pick it up on the next pull if this one fails.
        }

        return back()->with('success', $registrationId > 0
            ? "Linked \"{$impactName}\" to the entry. The next pull will post their score."
            : "Removed the mapping for \"{$impactName}\"."
        );
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

    /**
     * One click: email confirmed entrants a mail whose only button is
     * the match WhatsApp group invite.
     */
    public function emailWhatsappInvite(
        Request $request,
        MatchEvent $match,
        MatchWhatsappInviteMailer $mailer,
    ): RedirectResponse {
        $this->authorize('update', $match);

        $result = $mailer->send($request->user(), $match);

        return back()->with($result['ok'] ? 'success' : 'error', $result['message']);
    }

    /**
     * Move this match's unpaid non-members onto the club entry price.
     * Current club and provincial matches only. Paid rows are never touched.
     */
    public function applyClubEntry(
        Request $request,
        MatchEvent $match,
        RegistrationPricingService $pricing,
        AuditLogService $audit,
    ): RedirectResponse {
        $this->authorize('update', $match);

        if (! $match->supportsClubEntry()) {
            return back()->with('error', 'Club entry is only available on club or provincial matches.');
        }

        if (in_array($match->status, ['completed', 'cancelled'], true)) {
            return back()->with('error', 'Club entry can only be applied while the match is still open.');
        }

        $reason = 'Match director moved unpaid non-members onto the club entry price.';
        $updated = 0;

        foreach ($pricing->unpaidNonMembersEligibleForClubEntry($match) as $registration) {
            $result = $pricing->applyCategory($registration, 'club_entry', $reason);
            $audit->log(
                $request->user(),
                'registration.category.updated',
                'MatchRegistration',
                $registration->id,
                $result['old'],
                $result['new'],
                $reason,
            );
            $updated++;
        }

        if ($updated === 0) {
            return back()->with('success', 'No unpaid non-members on this match. Paid entries stay on the price already collected.');
        }

        return back()->with(
            'success',
            'Moved '.$updated.' unpaid '.($updated === 1 ? 'entry' : 'entries').' onto the club entry price. Paid entries were left unchanged.',
        );
    }

    // ── Scores (wizard-driven close-out) ──

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
        $wizard = $this->wizard->state($match);

        // Candidates for linking an assign-later Impact row to a real
        // match entry. Mirrors the picker on the import show page: all
        // live entries on the match (members and non-members) sorted by
        // display name.
        $linkRegistrations = MatchRegistration::query()
            ->with(['user:id,name', 'division:id,name,slug'])
            ->where('match_id', $match->id)
            ->where(function ($q) {
                $q->whereNull('registration_status')
                    ->orWhereNotIn('registration_status', ['cancelled']);
            })
            ->orderByRaw('COALESCE(shooter_name, "")')
            ->get()
            ->sortBy(fn (MatchRegistration $r) => Str::lower($r->user?->name ?: (string) $r->shooter_name))
            ->values();

        $divisions = $match->availableDivisions();

        return view('matches.workspace.scores', $this->workspaceData($match, 'scores', [
            'imports' => $imports,
            'scoresCount' => $scoresCount,
            'hasAnyScores' => $hasAnyScores,
            'counts' => $counts,
            'wizard' => $wizard,
            'pendingLinkScores' => $this->wizard->pendingLinkScores($match),
            'missingDivisionScores' => $this->wizard->missingDivisionScores($match),
            'unconfirmedZeroScores' => $this->wizard->unconfirmedZeroScores($match),
            'walkInRegistrations' => $match->walkInRegistrations(),
            'walkinAcknowledger' => $match->walkInFeesAreAcknowledged() ? $match->walkinFeesAcknowledger : null,
            'linkRegistrations' => $linkRegistrations,
            'divisions' => $divisions,
        ]));
    }

    /**
     * Override the division on an individual score from the names &
     * allocations step. For a stub / unlinked row this is the only way
     * to pick a division when the CSV didn't carry one. For a linked
     * row it also overwrites the entry-derived division — the MD takes
     * the write explicitly from the wizard, so we trust the choice.
     */
    public function updateScoreAllocation(Request $request, MatchEvent $match, Score $score): RedirectResponse
    {
        $this->authorize('update', $match);
        abort_unless((int) $score->match_id === (int) $match->id, 404);

        $data = $request->validate([
            'division_id' => ['required', 'exists:divisions,id'],
        ]);

        $divisionId = (int) $data['division_id'];
        $available = $match->availableDivisions()->pluck('id')->all();
        if (! in_array($divisionId, $available, true)) {
            return back()->with('error', 'That division is not on this match.');
        }

        $score->update(['division_id' => $divisionId]);

        app(StandingsCalculationService::class)->recalculateForMatch($match);

        return redirect()
            ->route('matches.scores', $match)
            ->with('success', 'Division updated for '.($score->shooter_name ?: 'score').'.');
    }

    /**
     * Step 3 of the wizard. The MD ticks the box that the platform fee and
     * the SAPRF levy are deducted from their payout for each walk-in on
     * this match — including the "no walk-ins on this match" explicit
     * acknowledgement so the gate is unambiguous for every path.
     */
    public function acknowledgeWalkinFees(Request $request, MatchEvent $match): RedirectResponse
    {
        $this->authorize('update', $match);

        $request->validate([
            'agreed' => ['required', 'accepted'],
        ]);

        $match->forceFill([
            'walkin_fees_acknowledged_at' => now(),
            'walkin_fees_acknowledged_by' => $request->user()->id,
        ])->save();

        return redirect()
            ->route('matches.scores', $match)
            ->with('success', 'Walk-in fees acknowledged. You can now mark scores complete.');
    }

    /**
     * Mark the scores on this match as complete — this is the MD's explicit
     * "yes, I have checked the names and provisional results, send the
     * notifications now" button on the scores tab. Deliberately separate
     * from `matches.status = completed` (which can flip automatically on
     * import / payout) and from "results published" (which happens the
     * moment any score row exists).
     *
     * The wizard puts this behind the names/allocations and walk-in
     * acknowledgement steps so a half-reviewed match can't accidentally
     * fire shooter notifications. Server-side guard mirrors the UI gate.
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

        $wizard = $this->wizard->state($match);
        if (in_array($wizard['current'], [MatchCloseoutWizard::STEP_NAMES, MatchCloseoutWizard::STEP_WALKINS], true)) {
            return redirect()
                ->route('matches.scores', $match)
                ->with('error', 'Finish the names and walk-in steps before marking scores complete.');
        }

        $wasFinalized = $match->scoresAreFinalized();

        $match->forceFill([
            'scores_finalized_at' => now(),
            'scores_finalized_by' => $request->user()->id,
        ])->save();

        app(ImpactLiveSyncService::class)->setConfirmed($match->fresh(), true);

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

        app(ImpactLiveSyncService::class)->setConfirmed($match->fresh(), false);

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

    // ── Status transitions ─────────────────────────────────────────────
    //
    // Replace the old free-form "Status" select that lived on the settings
    // form. Each transition is its own POST so the stage bar's Next-action
    // button and the chrome's More menu can wire a one-click action to it.
    // Policy gate is the same `update` ability the old select relied on;
    // `published` is kept in lockstep with status, matching the behaviour
    // of `MatchController::update` so draft matches stay off the public
    // calendar and non-draft matches appear on it.

    public function publishDraft(Request $request, MatchEvent $match): RedirectResponse
    {
        $this->authorize('update', $match);

        if (! $match->hasRequiredSetup()) {
            return redirect()->route('matches.settings', $match)
                ->with('error', 'Add a match director and entry fee before publishing.');
        }

        $this->transitionStatus($match, 'open', 'Match published and entries are open.');

        return redirect()->route('matches.overview', $match)
            ->with('success', 'Match published and entries are open.');
    }

    public function closeEntries(Request $request, MatchEvent $match): RedirectResponse
    {
        $this->authorize('update', $match);

        $this->transitionStatus($match, 'closed', 'Entries closed.');

        return redirect()->route('matches.overview', $match)
            ->with('success', 'Entries closed. You can still edit entries from the Entries tab.');
    }

    public function markCompleted(Request $request, MatchEvent $match): RedirectResponse
    {
        $this->authorize('update', $match);

        $this->transitionStatus($match, 'completed', 'Match marked as completed.');

        return redirect()->route('matches.overview', $match)
            ->with('success', 'Match marked as completed.');
    }

    public function cancelMatch(Request $request, MatchEvent $match): RedirectResponse
    {
        $this->authorize('update', $match);

        $this->transitionStatus($match, 'cancelled', 'Match cancelled.');

        return redirect()->route('matches.overview', $match)
            ->with('success', 'Match cancelled.');
    }

    public function revertToDraft(Request $request, MatchEvent $match): RedirectResponse
    {
        $this->authorize('update', $match);

        $this->transitionStatus($match, 'draft', 'Match reverted to draft.');

        return redirect()->route('matches.overview', $match)
            ->with('success', 'Match reverted to draft. It is hidden from the public calendar.');
    }

    /**
     * Flip the match status + the derived `published` flag, matching what
     * `MatchController::update` does when the old Status select changed.
     * Kept private so the five public transitions above are the only
     * entry points — callers can't accidentally skip the published sync.
     */
    private function transitionStatus(MatchEvent $match, string $status, string $flash): void
    {
        if ($match->status === $status) {
            return;
        }

        $match->update([
            'status' => $status,
            'published' => $status !== 'draft',
        ]);
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
