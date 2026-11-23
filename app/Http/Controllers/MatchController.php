<?php

namespace App\Http\Controllers;

use App\Http\Requests\ChangeMatchDirectorRequest;
use App\Http\Requests\StoreMatchRequest;
use App\Http\Requests\UpdateMatchRequest;
use App\Models\Division;
use App\Models\MatchEvent;
use App\Models\MatchRegistration;
use App\Models\NationalTeamAppearance;
use App\Models\Payment;
use App\Models\Payout;
use App\Models\Province;
use App\Models\Score;
use App\Models\User;
use App\Models\Venue;
use App\Notifications\EntryPaymentRequiredNotification;
use App\Notifications\MatchRegistrationConfirmedNotification;
use App\Notifications\TreasurerPayoutRequestNotification;
use App\Services\AccountCreditService;
use App\Services\AuditLogService;
use App\Services\FinancialService;
use App\Services\GuestShooterService;
use App\Services\ImpactScoring\ImpactScoringClient;
use App\Services\ImpactScoring\ImpactShooterExport;
use App\Services\MatchFinanceService;
use App\Services\MembershipValidationService;
use App\Services\PayFastService;
use App\Services\QualificationService;
use App\Services\RegistrationPricingService;
use App\Services\ScoreValidationService;
use App\Services\SettingsService;
use App\Services\StandingsCalculationService;
use App\Support\ScoreFormat;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MatchController extends Controller
{
    public function __construct(
        private readonly AuditLogService $auditLogService,
        private readonly AccountCreditService $accountCredits,
        private readonly SettingsService $settingsService,
        private readonly StandingsCalculationService $standings,
        private readonly ScoreValidationService $scoreValidation,
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

        // Filter bar: match_type (PRS/PR22), status (draft/open/closed/…),
        // and series_level (national/provincial/club/final/international).
        // Previously the filter dropdowns rendered but the controller never
        // read them — they just reloaded the page with no effect.
        if ($matchType = $request->input('match_type')) {
            if (in_array($matchType, ['PRS', 'PR22'], true)) {
                $query->where('match_type', $matchType);
            }
        }

        if ($status = $request->input('status')) {
            if (in_array($status, ['draft', 'open', 'closed', 'completed', 'cancelled'], true)) {
                $query->where('status', $status);
            }
        }

        if ($seriesLevel = $request->input('series_level')) {
            if (in_array($seriesLevel, ['national', 'provincial', 'club_only', 'club_provincial', 'final', 'international'], true)) {
                $query->where('series_level', $seriesLevel);
            }
        }

        $matches = $query->paginate(20)->withQueryString();

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
        unset($validated['divisions'], $validated['banner'], $validated['remove_banner'], $validated['poster'], $validated['remove_poster']);
        $baseFee = (float) ($validated['active_member_fee'] ?? 0);
        $nonMemberSurcharge = (float) $this->settingsService->get('non_member_surcharge', 0);
        $lapsedSurcharge = (float) $this->settingsService->get('lapsed_member_surcharge', 0);

        $validated['non_member_fee'] = $baseFee + $nonMemberSurcharge;
        $validated['lapsed_member_fee'] = $baseFee + $lapsedSurcharge;

        // Club entry is a club-hosted / provincial-only concept. Drop the
        // value on nationals/finals/internationals so a stale form field
        // can't book a club price on a match that is supposed to charge
        // the surcharge. The opt-in flag goes the same way: on nationals
        // it is meaningless and we don't want a hidden `true` to resurface
        // if the series level ever drops back to provincial.
        if (! in_array($validated['series_level'] ?? null, ['club_only', 'club_provincial', 'provincial'], true)) {
            $validated['club_entry_fee'] = null;
            $validated['club_entry_enabled'] = false;
        } else {
            $validated['club_entry_enabled'] = (bool) ($validated['club_entry_enabled'] ?? false);
        }

        // Host club and registration method are club-hosted-only concepts.
        // Other levels force `club_id` to null and always collect on-platform
        // with no external URL — a stale form field can't ship a shooter to
        // an outside site from a national match.
        if (! in_array($validated['series_level'] ?? null, ['club_only', 'club_provincial'], true)) {
            $validated['club_id'] = null;
            $validated['registration_method'] = 'platform';
            $validated['external_registration_url'] = null;
        } else {
            $validated['registration_method'] = $validated['registration_method'] ?? 'platform';
            if ($validated['registration_method'] !== 'external') {
                $validated['external_registration_url'] = null;
            }
        }

        // The "This is the SA Championships" flag is only meaningful on a
        // `final`. Any other series level forces it back to false so a
        // dropdown change can't leave a stale SA Champs flag behind on a
        // club or provincial match.
        if (($validated['series_level'] ?? null) !== 'final') {
            $validated['is_sa_championships'] = false;
        } else {
            $validated['is_sa_championships'] = (bool) ($validated['is_sa_championships'] ?? false);
        }

        $validated['series'] = $validated['match_type'];
        $validated['season'] = (string) now()->year;
        $validated['created_by'] = $request->user()->id;
        // The restructured Create form no longer carries a Status select —
        // every new match is a draft and the MD publishes it from the stage
        // bar on Settings. Default here keeps the Model's `saving` hook
        // (which syncs `published` off `status`) seeing a real value even
        // when the controller gets a minimal payload.
        $validated['status'] = $validated['status'] ?? 'draft';
        $validated['published'] = $validated['status'] !== 'draft';

        // Every match needs a named director; default to the creating account so
        // the match can be opened for sign-up without an extra step.
        if (empty($validated['match_director'])) {
            $validated['match_director'] = $request->user()->name;
        }

        if ($request->hasFile('banner')) {
            $validated['banner_path'] = $request->file('banner')->store('match-banners', 'public');
        }

        if ($request->hasFile('poster')) {
            $validated['poster_path'] = $request->file('poster')->store('match-posters', 'public');
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

        // Create is deliberately minimal — the MD lands on Settings next
        // to finish the setup (fees, contact, public page, scoring) and
        // publishes from the stage bar once they are ready.
        return redirect()->route('matches.settings', $match)
            ->with('success', 'Draft created. Add fees, contact details and the public page here, then publish from the bar above.');
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
        $actualShooters = $financeBreakdown['registration_count'] ?? 0;
        $totalExpenses = $expenses->sum(fn ($e) => $e->effectiveAmount($estimatedShooters));
        $totalExpensesActual = $expenses->sum(fn ($e) => $e->effectiveAmount($actualShooters));

        return view('matches.show', compact('match', 'financeBreakdown', 'planningEstimate', 'expenses', 'totalExpenses', 'totalExpensesActual', 'estimatedShooters', 'actualShooters'));
    }

    // Edit is a dead route — `Route::resource` excludes it and the
    // workspace serves /matches/{id}/edit as a 301 to the Settings tab.
    // The old `edit()` method rendered `matches.edit.blade.php` which no
    // longer exists; it has been removed so a future caller doesn't
    // silently resurrect a view that would 500.

    public function update(UpdateMatchRequest $request, MatchEvent $match): RedirectResponse
    {
        $old = $match->toArray();

        $validated = $request->validated();
        $divisionIds = $validated['divisions'] ?? [];
        $removeBanner = (bool) ($validated['remove_banner'] ?? false);
        $removePoster = (bool) ($validated['remove_poster'] ?? false);
        unset($validated['divisions'], $validated['banner'], $validated['remove_banner'], $validated['poster'], $validated['remove_poster']);

        if ($request->exists('impact_ref')) {
            $ref = trim((string) ($validated['impact_ref'] ?? ''));
            unset($validated['impact_ref']);
            $sync = $request->boolean('impact_live_sync');

            if ($ref === '') {
                $validated['impact_match_id'] = null;
                $validated['impact_live_sync'] = false;
                $validated['impact_live_ended_at'] = null;
                $validated['impact_live_error'] = null;
            } else {
                $newId = app(ImpactScoringClient::class)->parseMatchId($ref);
                if ((int) $match->impact_match_id !== $newId) {
                    $validated['impact_live_ended_at'] = null;
                    $validated['impact_live_error'] = null;
                }
                $validated['impact_match_id'] = $newId;
                $validated['impact_live_sync'] = $sync;
            }
        } else {
            unset($validated['impact_ref'], $validated['impact_live_sync']);
        }

        $baseFee = (float) ($validated['active_member_fee'] ?? $match->active_member_fee ?? 0);
        $nonMemberSurcharge = (float) $this->settingsService->get('non_member_surcharge', 0);
        $lapsedSurcharge = (float) $this->settingsService->get('lapsed_member_surcharge', 0);

        $validated['non_member_fee'] = $baseFee + $nonMemberSurcharge;
        $validated['lapsed_member_fee'] = $baseFee + $lapsedSurcharge;

        // Keep the club_entry_fee column honest if the series level moved off
        // a club-hosted or provincial value on this edit. For those three
        // levels the submitted value is accepted as-is (including blank,
        // which means "fall back to the active-member fee"); other series
        // levels always clear it. The opt-in flag is tied to the same levels
        // — force it off anywhere it can't apply, and normalise the checkbox
        // to a strict bool so an unchecked box doesn't leave the old value
        // in place.
        $effectiveLevel = $validated['series_level'] ?? $match->series_level;
        if (! in_array($effectiveLevel, ['club_only', 'club_provincial', 'provincial'], true)) {
            $validated['club_entry_fee'] = null;
            $validated['club_entry_enabled'] = false;
        } elseif (array_key_exists('club_entry_enabled', $validated) || $request->exists('club_entry_enabled')) {
            // Form always submits a hidden `0` before the checkbox, so a
            // present key with no checkbox means "unchecked, turn it off"
            // and an absent key means "this form didn't ask about the flag,
            // leave the stored value alone".
            $validated['club_entry_enabled'] = $request->boolean('club_entry_enabled');
        }

        // Host club and registration method only apply to club-hosted
        // matches. If this edit flips the level off `club_only` /
        // `club_provincial`, force the club_id null, snap registration back
        // to on-platform, and drop the external URL so a stale value can't
        // route a shooter off-site from a national or provincial match.
        if (! in_array($effectiveLevel, ['club_only', 'club_provincial'], true)) {
            $validated['club_id'] = null;
            $validated['registration_method'] = 'platform';
            $validated['external_registration_url'] = null;
        } else {
            // On a club-hosted match, only touch the method/URL when the form
            // submitted them. Settings forms that don't ask about them leave
            // the stored values alone.
            if (array_key_exists('registration_method', $validated) || $request->exists('registration_method')) {
                $method = $validated['registration_method'] ?? 'platform';
                $validated['registration_method'] = $method;
                if ($method !== 'external') {
                    $validated['external_registration_url'] = null;
                }
            } elseif (array_key_exists('external_registration_url', $validated)
                || $request->exists('external_registration_url')) {
                // URL came through on its own — keep it on the existing
                // method. If the existing method is not external, blank it.
                if ($match->registration_method !== 'external') {
                    $validated['external_registration_url'] = null;
                }
            }
        }

        // The "This is the SA Championships" flag only applies to `final`
        // matches. Anywhere else it is forced off so a series-level change
        // can't leave a stale SA Champs flag behind. The form always posts a
        // hidden `0` before the checkbox so we can tell "unchecked" from
        // "form did not ask".
        if ($effectiveLevel !== 'final') {
            $validated['is_sa_championships'] = false;
        } elseif (array_key_exists('is_sa_championships', $validated) || $request->exists('is_sa_championships')) {
            $validated['is_sa_championships'] = $request->boolean('is_sa_championships');
        }

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

        if ($request->hasFile('banner')) {
            if ($match->banner_path) {
                Storage::disk('public')->delete($match->banner_path);
            }
            $validated['banner_path'] = $request->file('banner')->store('match-banners', 'public');
        } elseif ($removeBanner && $match->banner_path) {
            Storage::disk('public')->delete($match->banner_path);
            $validated['banner_path'] = null;
        }

        if ($request->hasFile('poster')) {
            if ($match->poster_path) {
                Storage::disk('public')->delete($match->poster_path);
            }
            $validated['poster_path'] = $request->file('poster')->store('match-posters', 'public');
        } elseif ($removePoster && $match->poster_path) {
            Storage::disk('public')->delete($match->poster_path);
            $validated['poster_path'] = null;
        }

        $everyoneCountsChanged = array_key_exists('everyone_counts', $validated)
            && (bool) $old['everyone_counts'] !== (bool) $validated['everyone_counts'];

        $match->update($validated);

        // Only the full settings / edit form owns the division list. The
        // squads card on Entries posts name + match type + squadding JSON
        // and must not wipe divisions that were not on that form. An
        // unchecked "select all off" on Settings still clears them,
        // because that form always posts match_date.
        if ($request->exists('match_date') || $request->exists('divisions')) {
            $match->divisions()->sync($divisionIds);
        }

        // Flipping the "everyone counts" flag changes how every score on this
        // match should be graded (bypass vs full membership check). Standings
        // recalculation reads whatever is already persisted on each Score,
        // so without re-evaluating first, the toggle would either keep
        // demoting-eligible shooters "valid" (flag flipped OFF) or leave
        // lapsed shooters out of the log (flag flipped ON) until the next
        // sweep. Do the re-grade before the standings rebuild.
        if ($everyoneCountsChanged) {
            Score::query()
                ->where('match_id', $match->id)
                ->with(['user.membership', 'match'])
                ->chunkById(200, function ($scores): void {
                    foreach ($scores as $score) {
                        $this->scoreValidation->evaluateScoreStatus($score);
                    }
                });
        }

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

        $flash = 'Match updated successfully.';
        if ($match->status === 'cancelled') {
            $issued = $this->accountCredits->issueForCancelledMatch($match, $request->user());
            if ($issued->isNotEmpty()) {
                $flash .= ' Paid entry fees (R '.number_format((float) $issued->sum('amount'), 2).') were credited to the accounts that paid them.';
            }
        }
        if ($everyoneCountsChanged) {
            $flash .= ' Every score on this match was re-graded against the membership check.';
        }
        if ($recalculated) {
            $flash .= ' Standings were recalculated.';
        }

        return redirect()->route('matches.show', $match)
            ->with('success', $flash);
    }

    /**
     * Show the "Request MD payout" form.
     *
     * This is the GET half of the payout-request flow. The MD lands here from
     * the workspace Finances tab or the score-import success page, sees the
     * projected net amount (what the match will pay them), and fills in the
     * banking details SAPRF needs to transfer the money. The form POSTs back
     * to {@see completeAndRequestPayout()} below.
     *
     * A match has two slots: one before match day, and one from match day
     * on. Once the open slot is used, the form bounces to the latest statement.
     */
    public function showRequestPayout(
        Request $request,
        MatchEvent $match,
        FinancialService $financials,
        MatchFinanceService $workspaceMoney,
    ): View|RedirectResponse {
        $this->authorize('update', $match);

        if (! $match->registrationsAreClosed()) {
            return redirect()
                ->route('matches.finances', $match)
                ->with('error', 'You can request the payout once registration is closed.');
        }

        $phase = $match->openMdPayoutPhase();
        if ($phase === null) {
            $existingPayout = $match->mdPayouts()->sortByDesc('id')->first();
            $message = $match->mdPayoutRequestBlockReason();
            if ($existingPayout) {
                return redirect()
                    ->route('my-payouts.show', $existingPayout)
                    ->with('info', $message);
            }

            return redirect()
                ->route('matches.finances', $match)
                ->with('error', $message);
        }

        // Registration being closed is the only gate. Scores, names, and
        // walk-in acknowledgement can still be unfinished — the form warns
        // that a later payout can open negative if those land afterwards.

        // The slot figures are what this request will actually pay. `matchMoney`
        // adds the worst-case projection so the MD can see what is still outstanding.
        $summary = $financials->mdPayoutFinancials($match, $phase);
        $money = $workspaceMoney->matchMoney($match);
        $banking = Payout::lastBankingDetailsForUser($match->created_by);

        return view('matches.workspace.request-payout', [
            'match' => $match,
            'summary' => $summary,
            'money' => $money,
            'banking' => $banking,
            'payoutPhase' => $phase,
            'hasBeforeMatchPayout' => $match->hasBeforeMatchPayout(),
            'walkInsNeedingScores' => $match->walkInsNeedingScores(),
            'scoresNotImported' => ! $match->scores()->exists(),
        ]);
    }

    /**
     * Marks a match as completed AND creates a pending match-director payout in
     * one step. Triggered from the "Request payout" form so an MD who has just
     * uploaded results can close out the match and file for payment in one
     * flow, instead of chasing an admin by WhatsApp.
     *
     * Banking details are required: an MD who doesn't tell us where to pay
     * them is just asking an admin to interrupt them later. We validate the
     * four fields on the way in and persist them onto the payout record so
     * the admin can process the transfer without further back-and-forth.
     *
     * One before-match payout and one post-match payout. A second request
     * in the same slot is refused. The post-match payout only includes
     * entries the earlier payout did not already claim.
     */
    public function completeAndRequestPayout(
        Request $request,
        MatchEvent $match,
        FinancialService $financials,
    ): RedirectResponse {
        $this->authorize('update', $match);

        if (! $match->registrationsAreClosed()) {
            return redirect()
                ->route('matches.finances', $match)
                ->with('error', 'You can request the payout once registration is closed.');
        }

        $back = url()->previous() ?: route('matches.show', $match);

        if ($match->openMdPayoutPhase() === null) {
            return redirect($back)->with('error', $match->mdPayoutRequestBlockReason());
        }

        $banking = $request->validate([
            'account_holder' => ['required', 'string', 'max:255'],
            'bank_name' => ['required', 'string', 'max:100'],
            // Account numbers vary by bank (6–11 digits for the big four,
            // longer for a few business accounts). Keep it permissive but
            // require at least six digits so a typo stays out of the queue.
            'account_number' => ['required', 'string', 'min:6', 'max:30'],
            // SA universal branch codes are always six digits.
            'branch_code' => ['required', 'string', 'size:6'],
        ]);

        $old = $match->only(['status', 'published']);

        // A payout is a payment, not the end of the match. A 2-day national
        // can be paid out while day 2 is still on the range; marking it
        // completed here dropped it off the public live board and stopped
        // the Impact pull.
        try {
            $payout = $financials->createMdPayout(
                $match,
                $request->user(),
                'Requested by match director with banking details.',
                $banking,
            );
        } catch (RuntimeException $e) {
            return redirect($back)->with('error', $e->getMessage());
        }

        $this->auditLogService->log(
            $request->user(),
            'md_payout_requested',
            'payout',
            $payout->id,
            $old,
            [
                'match_id' => $match->id,
                'payout_reference' => $payout->reference,
                'payout_phase' => $payout->payout_phase,
                'net_amount' => $payout->net_amount,
                // Record that banking details were captured, but never log
                // the actual account number — audit log entries are visible
                // to admins and shouldn't double as a credential dump.
                'banking_captured' => true,
            ],
        );

        $this->notifyTreasurerOfPayoutRequest($payout);

        $which = $payout->payout_phase === 'before_match' ? 'Before-match payout' : 'Post-match payout';
        $message = "{$which} {$payout->reference} requested for R".number_format($payout->net_amount, 2).'. An admin will process the payment. The match stays open.';
        if ($payout->payout_phase === 'before_match') {
            $message .= ' Comp and cash deductions are on this payout. Entries that pay later can be requested once the match has started.';
        }
        if (! $match->scoresAreFinalized()) {
            $message .= ' Scores stay provisional, and a live Impact pull keeps running through the end date. Mark the scores complete when you are ready so shooters are notified. If walk-ins or other deductions still come in, a later payout can start off negative.';
        }

        return redirect()->route('my-payouts.show', $payout)->with('success', $message);
    }

    /**
     * Queue a reminder to every user whose Exco portfolio is Treasurer.
     * Two hours later, and only if the payout is still pending when the
     * mail is built. Missing a treasurer must not fail the MD's request.
     */
    private function notifyTreasurerOfPayoutRequest(Payout $payout): void
    {
        $treasurers = User::query()
            ->whereRaw('LOWER(exco_position) = ?', ['treasurer'])
            ->get();

        if ($treasurers->isEmpty()) {
            Log::warning('MD payout requested but no treasurer is on file', [
                'payout_id' => $payout->id,
            ]);

            return;
        }

        Notification::send(
            $treasurers,
            (new TreasurerPayoutRequestNotification($payout))->delay(now()->addHours(2)),
        );
    }

    /**
     * EXCO-only action to reassign match ownership. Transfers `created_by`
     * to the selected user, syncs the display director name (and contact
     * from the user's profile when available), and grants the target the
     * `match_director` role if they don't already have it. The previous
     * director keeps their role — they likely own other matches.
     */
    public function changeDirector(ChangeMatchDirectorRequest $request, MatchEvent $match): RedirectResponse
    {
        $newDirector = User::findOrFail($request->integer('user_id'));

        $old = $match->only(['created_by', 'match_director', 'match_director_contact']);

        if (! $newDirector->hasRole('match_director')) {
            $newDirector->assignRole('match_director');
        }

        $match->created_by = $newDirector->id;
        $match->match_director = $newDirector->name;
        // Only overwrite contact when we actually have something better —
        // don't blank out a manually-set contact string just because the
        // new director's profile has no phone on file.
        if (! empty($newDirector->phone)) {
            $match->match_director_contact = $newDirector->phone;
        }
        $match->save();

        $this->auditLogService->log(
            $request->user(),
            'match_director_changed',
            'match',
            $match->id,
            $old,
            [
                'created_by' => $match->created_by,
                'match_director' => $match->match_director,
                'match_director_contact' => $match->match_director_contact,
            ],
        );

        return redirect()->route('matches.show', $match)
            ->with('success', "Match director changed to {$newDirector->name}.");
    }

    /**
     * Autocomplete for the EXCO "change director" picker. Scoped to the
     * same role gate as the action itself (see routes). Returns any active
     * platform user by name or SAPRF number — including the caller, so
     * EXCO members can assign themselves as director when taking a match
     * over.
     */
    public function directorCandidateSearch(Request $request): JsonResponse
    {
        $term = trim((string) $request->input('q', ''));

        if (mb_strlen($term) < 2) {
            return response()->json(['results' => []]);
        }

        $like = '%'.$term.'%';

        $users = User::query()
            ->select('users.id', 'users.name', 'users.email')
            ->leftJoin('memberships', 'memberships.user_id', '=', 'users.id')
            ->addSelect('memberships.saprf_number as saprf_number')
            ->where('users.is_active', true)
            ->where(function ($q): void {
                $q->whereNull('users.is_managed_account')
                    ->orWhere('users.is_managed_account', false);
            })
            ->where(function ($q) use ($like, $term): void {
                $q->where('users.name', 'like', $like)
                    ->orWhere('memberships.saprf_number', 'like', $term.'%');
            })
            ->orderBy('users.name')
            ->limit(10)
            ->get();

        return response()->json([
            'results' => $users->map(fn (User $u) => [
                'id' => $u->id,
                'name' => $u->name,
                'email' => $u->email,
                'saprf_number' => $u->saprf_number,
            ])->values(),
        ]);
    }

    public function exportImpactScoringCsv(MatchEvent $match, ImpactShooterExport $export): StreamedResponse
    {
        $this->authorize('update', $match);

        $built = $export->build($match);

        if ($built['omitted'] !== []) {
            Log::warning('Impact scoring export omitted registrations', [
                'match_id' => $match->id,
                'missing_registration_ids' => array_column($built['omitted'], 'id'),
            ]);
        }

        $filename = str($match->name)->slug().'-impact-scoring.csv';

        return response()->streamDownload(function () use ($built) {
            $out = fopen('php://output', 'w');
            fputcsv($out, $built['headers']);
            foreach ($built['rows'] as $row) {
                fputcsv($out, $row);
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

        // Phase 2.5: nest Day-1 provincial siblings under their parent
        // 2-day national. Children (rows with a source_national_match_id)
        // are hidden from the main grid; the card component shows them
        // nested under the parent instead. `?include_children=1` is an
        // audit escape hatch so the full flat list is still reachable.
        $includeChildren = $request->boolean('include_children');

        $baseQuery = MatchEvent::query()
            ->published()
            ->with(['province', 'creator:id,name'])
            ->when(! $includeChildren, fn ($q) => $q->whereNull('source_national_match_id'))
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
            // Results tab: load every visible score with its division so the
            // card can render overall top-3 AND one line per division winner.
            // Also eager-load the day-1 provincial sibling for 2-day nationals
            // so parent cards can advertise the paired provincial event.
            $query = (clone $baseQuery)
                ->past()
                ->with([
                    'scores' => fn ($q) => $q
                        ->whereIn('status', ScoreValidationService::VISIBLE_STATUSES)
                        ->with('division:id,name,display_order')
                        ->orderBy('overall_rank'),
                    'provincialDay1Match:id,name,source_national_match_id,match_date',
                ])
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

        // Phase 4.8: eager-load each score's user + membership so the view can
        // link shooter names to their canonical profile (SAPRF number when
        // available, legacy user-id fallback otherwise).
        $match->load(['province', 'creator:id,name', 'scores' => fn ($q) => $q->whereIn('status', ScoreValidationService::VISIBLE_STATUSES)->with(['division', 'user:id,name,country_of_residence', 'user.membership:id,user_id,saprf_number'])->orderBy('overall_rank')]);
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

        // Admins and the MD who owns the match get an inline "remove zero-score
        // shooter" affordance in the public results table — same effect as the
        // score-imports review banner, but reachable from wherever they happen
        // to be reviewing results. Non-privileged viewers never see the flag.
        $viewer = Auth::user();
        $viewerCanRemoveZeroScores = $viewer !== null && (
            $viewer->hasRole(['owner', 'admin', 'exco', 'developer'])
            || $match->created_by === $viewer->id
        );

        // Score downloads (CSV / PDF) are MD + admin/exco only — never public.
        // Rank-and-file members and anonymous visitors read scores on the page
        // but don't get a bulk-export of the field. Same cohort as the
        // zero-score-removal gate above, so the two sit together naturally.
        $viewerCanDownloadResults = $viewer !== null && (
            $viewer->hasRole(['owner', 'admin', 'exco', 'developer'])
            || $match->created_by === $viewer->id
        );

        // "SA 2026" chips next to shooter names in both the results table
        // and the entry list. One bulk query covers both sets so the Alpine
        // row payloads stay N+1-safe.
        $saBadgeUserIds = $match->scores->pluck('user_id')
            ->merge($entries->pluck('user_id'))
            ->filter()
            ->unique()
            ->values()
            ->all();
        $saBadgesByUser = NationalTeamAppearance::badgeDataForUsers($saBadgeUserIds);

        // Phase 4.2: admin-only import attribution. Only load the trail when
        // the viewer is actually an admin (saves N+1 for every anon visitor),
        // and the view guards the whole block with the same role check.
        $scoreImports = $viewer !== null && $viewer->hasRole(['owner', 'admin', 'exco', 'developer'])
            ? $match->scoreImports()->with('uploader:id,name')->orderByDesc('created_at')->get()
            : collect();

        $board = $this->buildLiveBoardPayload($match, $viewerCanRemoveZeroScores, $saBadgesByUser);
        $scoreData = $board['rows'];
        $divList = $board['divisions'];
        $showStageProgress = $board['has_stage_progress'];

        // Post-match survey CTA — only renders for a logged-in shooter who
        // entered or shot the match, while the window is open, and only if
        // they have not already answered. Kept out of the public list / for
        // guests so nobody can guess at the eligible-shooter list by probing.
        $surveys = app(\App\Services\MatchSurveyService::class);
        $viewerCanTakeSurvey = $viewer !== null
            && $surveys->userMayAnswer($match, $viewer);

        return view('events.show', compact(
            'match', 'userRegistration', 'divisions', 'entries', 'reconciliation',
            'viewerCanRemoveZeroScores', 'viewerCanDownloadResults', 'saBadgesByUser', 'scoreImports',
            'scoreData', 'divList', 'showStageProgress', 'viewerCanTakeSurvey',
        ));
    }

    /**
     * JSON payload for the public live scoreboard — same shape the Alpine
     * component receives on first render. Lets the page poll for fresh
     * scores without a full reload. Draft / unpublished matches 404, and
     * a match that is finalized or has Impact pull switched off returns
     * `"live": false` so the client stops polling and reloads once to
     * pick up the official-results layout.
     */
    public function liveScoresJson(MatchEvent $match)
    {
        if ($match->status === 'draft' || ! $match->published) {
            abort(404);
        }

        $isLive = ! $match->scoresAreFinalized()
            && ($match->impact_live_sync || $match->impact_live_synced_at);

        if (! $isLive) {
            return response()->json(['live' => false]);
        }

        $match->load(['scores' => fn ($q) => $q->whereIn('status', ScoreValidationService::VISIBLE_STATUSES)
            ->with(['division', 'user:id,name,country_of_residence', 'user.membership:id,user_id,saprf_number'])
            ->orderBy('overall_rank')]);

        $viewer = Auth::user();
        $viewerCanRemoveZeroScores = $viewer !== null && (
            $viewer->hasRole(['owner', 'admin', 'exco', 'developer'])
            || $match->created_by === $viewer->id
        );

        $saBadgeUserIds = $match->scores->pluck('user_id')->filter()->unique()->values()->all();
        $saBadgesByUser = NationalTeamAppearance::badgeDataForUsers($saBadgeUserIds);

        $board = $this->buildLiveBoardPayload($match, $viewerCanRemoveZeroScores, $saBadgesByUser);

        return response()->json([
            'live' => true,
            'rows' => $board['rows'],
            'divisions' => $board['divisions'],
            'has_stage_progress' => $board['has_stage_progress'],
            'has_any_tie' => $board['has_any_tie'],
            'synced_at' => $match->impact_live_synced_at?->timezone('Africa/Johannesburg')->format('H:i:s'),
            'synced_at_iso' => $match->impact_live_synced_at?->toIso8601String(),
            'ended' => $match->impact_live_ended_at !== null,
        ]);
    }

    /**
     * One source of truth for the public live scoreboard rows — called by
     * publicShow for the first render and by liveScoresJson for each poll.
     *
     * @param  array<int, array{years: list<int>, tooltip: ?string}>  $saBadgesByUser
     * @return array{
     *     rows: list<array<string, mixed>>,
     *     divisions: list<array{id: int, name: string}>,
     *     has_stage_progress: bool,
     *     has_any_tie: bool,
     * }
     */
    private function buildLiveBoardPayload(MatchEvent $match, bool $viewerCanRemoveZeroScores, array $saBadgesByUser): array
    {
        $divisionLeaderRaw = $match->scores
            ->filter(fn ($s) => $s->division_id !== null && $s->raw_score !== null)
            ->groupBy('division_id')
            ->map(fn ($rows) => (float) $rows->max('raw_score'));
        $overallLeaderRaw = (float) ($match->scores->max('raw_score') ?: 0.0);

        $rows = $match->scores->sortBy('overall_rank')->map(function ($score) use ($viewerCanRemoveZeroScores, $saBadgesByUser, $match, $divisionLeaderRaw, $overallLeaderRaw) {
            $saprfNumber = $score->user?->membership?->saprf_number;
            $profileUrl = null;
            if ($score->user_id !== null) {
                $profileUrl = $saprfNumber
                    ? route('shooters.show', ['saprfNumber' => $saprfNumber])
                    : route('standings.shooter', ['season' => $match->season ?? now()->year, 'user' => $score->user_id]);
            }
            $saBadge = $saBadgesByUser[$score->user_id] ?? null;
            $orderedStages = \App\Support\ImpactStages::forDisplay($score->raw_meta['impact']['stages'] ?? null);
            $countryCode = $score->user?->country_of_residence;
            $country = $countryCode && $countryCode !== 'ZA'
                ? (User::COUNTRY_OPTIONS[$countryCode] ?? null)
                : null;

            return [
                'id' => $score->id,
                'name' => $score->shooter_name,
                'raw' => (float) $score->raw_score,
                'raw_display' => \App\Support\ScoreFormat::format($score->raw_score, $match->match_type, $match->series),
                'norm' => $score->normalized_score ? round((float) $score->normalized_score, 2) : null,
                'div_norm' => $score->division_normalized_score ? round((float) $score->division_normalized_score, 2) : null,
                'match_pct' => $overallLeaderRaw > 0 && $score->raw_score !== null
                    ? round(((float) $score->raw_score) / $overallLeaderRaw * 100, 2)
                    : null,
                'div_pct' => $score->division_id !== null
                    && isset($divisionLeaderRaw[$score->division_id])
                    && $divisionLeaderRaw[$score->division_id] > 0
                    && $score->raw_score !== null
                    ? round(((float) $score->raw_score) / $divisionLeaderRaw[$score->division_id] * 100, 2)
                    : null,
                'overall_rank' => $score->overall_rank,
                'div_rank' => $score->division_rank,
                'is_tied_overall' => (bool) $score->is_tied_overall,
                'is_tied_division' => (bool) $score->is_tied_division,
                'div_id' => $score->division_id,
                'div_name' => $score->division?->name ?? '—',
                'status' => $score->status,
                'profile_url' => $profileUrl,
                'can_remove' => $viewerCanRemoveZeroScores && (float) $score->raw_score === 0.0,
                'country' => $country,
                'badge' => match ($score->status) {
                    'non_member' => ['label' => 'Non-member', 'class' => 'bg-stone-100 text-stone-600 ring-stone-200', 'tip' => 'This shooter was not a SAPRF member on the match date — their score is displayed but does not contribute to season standings.'],
                    'lapsed'     => ['label' => 'Lapsed',     'class' => 'bg-rose-50 text-rose-700 ring-rose-200', 'tip' => 'This shooter had a lapsed membership on the match date — their score is displayed but does not contribute to season standings.'],
                    'pending'    => ['label' => 'Pending',    'class' => 'bg-amber-50 text-amber-700 ring-amber-200', 'tip' => 'The match director has not yet confirmed this score. It will update when verified.'],
                    default      => null,
                },
                'stages' => $orderedStages === [] ? null : $orderedStages,
                'stages_label' => isset($score->raw_meta['impact']['stages_completed'])
                    ? ((int) $score->raw_meta['impact']['stages_completed'])
                        .(isset($score->raw_meta['impact']['stages_total']) ? ' / '.(int) $score->raw_meta['impact']['stages_total'] : '')
                    : null,
                'sa_years' => $saBadge['years'] ?? [],
                'sa_tooltip' => $saBadge['tooltip'] ?? null,
            ];
        })->values()->toArray();

        $divisions = $match->scores->pluck('division')
            ->filter()
            ->unique('id')
            ->sortBy('display_order')
            ->map(fn ($d) => ['id' => $d->id, 'name' => $d->name])
            ->values()
            ->toArray();

        return [
            'rows' => $rows,
            'divisions' => $divisions,
            'has_stage_progress' => $match->scores->contains(
                fn ($s) => isset($s->raw_meta['impact']['stages_completed'])
            ),
            'has_any_tie' => $match->scores->contains(fn ($s) => $s->is_tied_overall || $s->is_tied_division),
        ];
    }

    /**
     * CSV export of a match's visible scores. Gated to the match's MD and
     * admin/exco/owner/developer roles — bulk-downloading the whole field
     * is a staff action, not a public one. Honours `?division=` so an
     * MD can share a one-division printout as a single direct URL.
     *
     * The route name stays `events.results.csv` for backward-compat; "public"
     * in the method name means "outside the /matches admin area", not
     * "unauthenticated". Non-privileged viewers get a 404 so the URL stays
     * invisible rather than confirming the resource exists.
     */
    public function publicExportCsv(Request $request, MatchEvent $match): StreamedResponse
    {
        if ($match->status === 'draft' || ! $match->published) {
            abort(404);
        }

        $this->abortIfNotAllowedToDownloadResults($request, $match);

        $divisionFilter = $request->filled('division') ? (int) $request->input('division') : null;

        $scores = $match->scores()
            ->whereIn('status', ScoreValidationService::VISIBLE_STATUSES)
            ->when($divisionFilter, fn ($q) => $q->where('division_id', $divisionFilter))
            ->with(['division:id,name,display_order', 'user:id,name'])
            ->orderByRaw('overall_rank IS NULL, overall_rank ASC')
            ->orderByDesc('raw_score')
            ->get();

        $divisionLeaderRaw = $match->scores
            ->filter(fn ($s) => $s->division_id !== null && $s->raw_score !== null)
            ->groupBy('division_id')
            ->map(fn ($rows) => (float) $rows->max('raw_score'));
        $overallLeaderRaw = (float) ($match->scores->max('raw_score') ?: 0.0);

        $filename = str($match->name)->slug().'-results'
            .($divisionFilter ? '-'.str($scores->first()?->division?->name ?? 'division')->slug() : '')
            .'.csv';

        return response()->streamDownload(function () use ($scores, $divisionLeaderRaw, $overallLeaderRaw, $match) {
            $out = fopen('php://output', 'w');
            fputcsv($out, [
                'Overall rank',
                'Division rank',
                'Shooter',
                'Division',
                'Score',
                'Match %',
                'Division %',
                'Status',
            ]);

            foreach ($scores as $score) {
                $divLeader = $score->division_id ? ($divisionLeaderRaw[$score->division_id] ?? 0.0) : 0.0;
                $matchPct = $overallLeaderRaw > 0 && $score->raw_score !== null
                    ? round(((float) $score->raw_score) / $overallLeaderRaw * 100, 2)
                    : null;
                $divPct = $divLeader > 0 && $score->raw_score !== null
                    ? round(((float) $score->raw_score) / $divLeader * 100, 2)
                    : null;

                fputcsv($out, [
                    $score->overall_rank_label ?? '—',
                    $score->division_rank_label ?? '—',
                    $score->shooter_name,
                    $score->division?->name ?? '',
                    ScoreFormat::format($score->raw_score, $match->match_type, $match->series),
                    $matchPct !== null ? number_format($matchPct, 2) : '',
                    $divPct !== null ? number_format($divPct, 2) : '',
                    $score->status,
                ]);
            }

            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv',
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
        ]);
    }

    /**
     * PDF export mirroring the CSV layout. Same MD/staff-only gate as the
     * CSV route — see publicExportCsv() for the reasoning. DomPDF is already
     * a project dep so we reuse it rather than inventing a new one.
     */
    public function publicExportPdf(Request $request, MatchEvent $match)
    {
        if ($match->status === 'draft' || ! $match->published) {
            abort(404);
        }

        $this->abortIfNotAllowedToDownloadResults($request, $match);

        $divisionFilter = $request->filled('division') ? (int) $request->input('division') : null;

        $scores = $match->scores()
            ->whereIn('status', ScoreValidationService::VISIBLE_STATUSES)
            ->when($divisionFilter, fn ($q) => $q->where('division_id', $divisionFilter))
            ->with(['division:id,name,display_order', 'user:id,name'])
            ->orderByRaw('overall_rank IS NULL, overall_rank ASC')
            ->orderByDesc('raw_score')
            ->get();

        $divisionName = $divisionFilter ? ($scores->first()?->division?->name ?? 'Division') : null;

        $overallLeaderRaw = (float) ($match->scores->max('raw_score') ?: 0.0);
        $divisionLeaderRaw = $match->scores
            ->filter(fn ($s) => $s->division_id !== null && $s->raw_score !== null)
            ->groupBy('division_id')
            ->map(fn ($rows) => (float) $rows->max('raw_score'));

        $pdf = Pdf::loadView('events.results-pdf', [
            'match' => $match,
            'scores' => $scores,
            'divisionName' => $divisionName,
            'overallLeaderRaw' => $overallLeaderRaw,
            'divisionLeaderRaw' => $divisionLeaderRaw,
        ])->setPaper('A4', 'portrait');

        $filename = str($match->name)->slug().'-results'
            .($divisionFilter ? '-'.str($divisionName ?? 'division')->slug() : '')
            .'.pdf';

        return $pdf->download($filename);
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
        /** @var User $actor */
        $actor = $request->user();

        // Sponsor path for a shooter not yet on the platform: display the
        // register page with an in-memory User carrying the name/email the
        // sponsor typed. We deliberately DO NOT persist the stub here —
        // the record is only created on POST, so an abandoned preview
        // doesn't leave a ghost account behind.
        $newShooterName = trim((string) $request->input('new_shooter_name', ''));
        $newShooterEmail = trim((string) $request->input('new_shooter_email', ''));
        $isNewShooter = $newShooterName !== '' && ! $request->filled('for_user');

        // "?sponsor=1" is the intent signal from the "Enter or pay for another
        // member" affordance on the event page. It matters when the actor is
        // already registered themselves: without it, we'd bounce them straight
        // back to their own entry and they'd never reach the sponsor panel.
        $sponsorIntent = $request->boolean('sponsor')
            && ! $isNewShooter
            && ! $request->filled('for_user');

        // Tracks whether the actor already has an entry on this match — used
        // by the register view (in sponsor-intent mode) to suppress the
        // "register myself" form and only show the sponsor search.
        $actorAlreadyRegistered = false;
        $openSponsorPanel = $sponsorIntent;

        if ($isNewShooter) {
            $shooter = new User([
                'name' => $newShooterName,
                'email' => $newShooterEmail !== '' ? $newShooterEmail : null,
            ]);
        } else {
            // Resolve who we're registering: self, one of the actor's managed
            // family accounts, or an independent member the actor is sponsoring.
            $shooter = $this->resolveShooter($actor, $request->input('for_user'));

            $isSelf = $shooter->id === $actor->id;

            $existing = $match->userRegistration($shooter);
            if ($existing) {
                // Sponsor intent overrides the "already registered" bounce for
                // the actor themselves — they're not trying to re-register, they
                // want to enter someone else. Render the page with the sponsor
                // panel open and a notice; the "register myself" form is hidden.
                if ($isSelf && $sponsorIntent) {
                    $actorAlreadyRegistered = true;
                } else {
                    return redirect()->route('registrations.show', $existing)
                        ->with('info', $isSelf
                            ? 'You are already registered for this match.'
                            : $shooter->name.' is already registered for this match.');
                }
            }

            // Unpaid fee on another match blocks new portal entries until paid.
            // Opening the sponsor panel for someone else is fine even if the
            // actor themselves still owes — the POST checks the target shooter.
            if (! ($isSelf && $sponsorIntent)) {
                if ($blocked = $this->redirectIfOutstandingBlocksEntry($shooter, $actor)) {
                    return $blocked;
                }
            }
        }

        if (! $match->hasRequiredSetup()) {
            return redirect()->route('events.show', $match)
                ->with('info', 'This match isn’t open for sign-up yet — the match director still needs to set the entry fee and details.');
        }

        if (! $match->isRegistrationOpen() && ! $match->isWaitlistOpen() && ! $match->staffMayEnterDespiteClosedRegistration($actor)) {
            return redirect()->route('events.show', $match)
                ->with('error', 'Registration is closed for this match.');
        }

        // Sponsor-only view (the actor is already entered and came to add
        // someone else) stays open so they can look up a full member. Every
        // other visit is for the resolved shooter, and a new name is always
        // a club-tier shooter.
        $skipClubGate = $actorAlreadyRegistered && $sponsorIntent;
        $entrySubject = $isNewShooter ? null : $shooter;
        if (! $skipClubGate && ! $match->allowsShooter($entrySubject)) {
            return redirect()->route('events.show', $match)
                ->with(
                    'error',
                    app(MembershipValidationService::class)->matchEntryDenialMessage($match, $entrySubject)
                        ?? MembershipValidationService::PROVINCIAL_MEMBERS_ONLY_MESSAGE,
                );
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

        // Preload each managed junior's registration on this match so the view
        // can render the right per-junior action (Enter / Pay / Already
        // entered) without an N+1 lookup. Keyed by user_id for O(1) access.
        $juniorEntries = $juniors->isNotEmpty()
            ? MatchRegistration::query()
                ->where('match_id', $match->id)
                ->whereIn('user_id', $juniors->pluck('id'))
                ->get()
                ->keyBy('user_id')
            : collect();

        $divisions = $match->availableDivisions();

        // Junior-division entries may carry a discounted fee — surface both the
        // normal and junior totals so the form can switch the price live.
        $juniorPricing = $match->junior_fee !== null
            ? app(RegistrationPricingService::class)->determineCategoryAndFee($match, $pricingSubject, $match->match_date, 'junior')
            : null;
        $juniorDivisionId = $divisions->firstWhere('slug', 'junior')?->id;

        return view('events.register', compact('match', 'pricing', 'rifles', 'defaultRifleId', 'shooter', 'juniors', 'juniorEntries', 'divisions', 'juniorPricing', 'juniorDivisionId', 'isNewShooter', 'openSponsorPanel', 'actorAlreadyRegistered'));
    }

    public function storeRegistration(Request $request, MatchEvent $match): RedirectResponse
    {
        /** @var User $actor */
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

            if (! $match->allowsShooter(null)) {
                return back()->with(
                    'error',
                    app(MembershipValidationService::class)->matchEntryDenialMessage($match, null)
                        ?? MembershipValidationService::PROVINCIAL_MEMBERS_ONLY_MESSAGE,
                );
            }

            $shooter = app(GuestShooterService::class)->findOrCreate(
                $newShooterName,
                $newShooterEmail !== '' ? $newShooterEmail : null,
            );
        } else {
            $shooter = $this->resolveShooter($actor, $request->input('for_user'));

            if (! $match->allowsShooter($shooter)) {
                return back()->with(
                    'error',
                    app(MembershipValidationService::class)->matchEntryDenialMessage($match, $shooter)
                        ?? MembershipValidationService::PROVINCIAL_MEMBERS_ONLY_MESSAGE,
                );
            }
        }

        $existing = $match->userRegistration($shooter);
        if ($existing) {
            $isSelf = $shooter->id === $actor->id;

            return redirect()->route('registrations.show', $existing)
                ->with('info', $isSelf
                    ? 'You are already registered for this match.'
                    : $shooter->name.' is already registered for this match.');
        }

        if ($blocked = $this->redirectIfOutstandingBlocksEntry($shooter, $actor)) {
            return $blocked;
        }

        if (! $match->isRegistrationOpen() && ! $match->isWaitlistOpen() && ! $match->staffMayEnterDespiteClosedRegistration($actor)) {
            return back()->with('error', 'Registration is not available for this match.');
        }

        $qualification = app(QualificationService::class);
        $champsEntry = $qualification->championshipEntryStatus($shooter, $match);
        if (! $champsEntry['eligible']) {
            return back()->with('error', $qualification->championshipEntryMessage(
                $champsEntry,
                $match->series,
                $shooter->id === $actor->id ? 'You' : $shooter->name,
            ));
        }

        $allowedDivisionIds = $match->availableDivisions()->pluck('id')->all();

        $validated = $request->validate([
            'rifle_configuration_id' => ['nullable', 'exists:rifle_configurations,id'],
            'division_id' => ['required', Rule::in($allowedDivisionIds)],
            'notes' => ['nullable', 'string', 'max:500'],
        ], [
            'division_id.required' => 'Please choose a division to enter.',
            'division_id.in' => 'The selected division is not available for this match.',
        ]);

        $divisionSlug = Division::whereKey($validated['division_id'])->value('slug');

        $breakdown = app(RegistrationPricingService::class)
            ->calculateBreakdown($match, $shooter, $match->match_date, $divisionSlug);

        // External match: the money reaches the host club off-platform, so
        // no PayFast transaction ever happens. The estimated gateway fee
        // (card-rate forecast for unpaid/cash rows) would silently shave
        // ~R20 off the MD's payout — zero it and credit the MD the same
        // way an off-platform settlement does elsewhere.
        if ($match->usesExternalRegistration() && (float) $breakdown['gateway_fee'] > 0) {
            $breakdown['md_net'] = round(
                (float) $breakdown['md_net'] + (float) $breakdown['gateway_fee'],
                2,
            );
            $breakdown['gateway_fee'] = 0.0;
        }

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

        $registration = MatchRegistration::query()->create([
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

        // External registration: SAPRF entry is captured and the shooter is
        // handed off to the host club's own page (entry form, Google Form,
        // bank-details page). No PayFast, no entry credit — the MD marks
        // the entry paid once the club receives the money. Any success
        // flash is a short visible message for the brief redirect frame.
        if ($match->usesExternalRegistration()) {
            $hostName = $match->club?->name ?: 'the host club';

            return redirect()->away($match->external_registration_url)
                ->with('success', 'Entry saved. Finish registering with '.$hostName.' at the link.');
        }

        // Entry credit (cancelled match, or an MD removing a paid entry) is
        // spent first; the pay flow tops up the remainder through PayFast.
        if ($breakdown['total_fee'] > 0 && $this->accountCredits->summary($actor)['available'] > 0) {
            return app(PaymentController::class)->payRegistration($request, $registration);
        }

        $payFastService = app(PayFastService::class);

        if ($payFastService->isEnabled() && $breakdown['total_fee'] > 0) {
            // Payment is made by whoever initiated the entry: the parent for a
            // managed junior, the sponsor when sponsoring, or the shooter
            // themselves. Never a stranger.
            $payer = $isSelf ? $shooter : $actor;

            $payment = Payment::create([
                'payable_type' => MatchRegistration::class,
                'payable_id' => $registration->id,
                'user_id' => $payer->id,
                'amount' => $breakdown['total_fee'],
                'm_payment_id' => Payment::generateReference('REG'),
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
            'division_id' => ['nullable', 'integer', Rule::in($allowedDivisionIds)],
            'waive_lapsed_surcharge' => ['sometimes', 'boolean'],
            'fee_override_reason' => ['nullable', 'string', 'max:500'],
            'collection' => ['nullable', Rule::in(['paid', 'due'])],
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

        // A brand-new shooter is a club-tier non-member. Refuse them on a
        // members-only provincial (or Club / Provincial), and on a Club
        // only with a host club set, before provisioning a stub.
        if (! $hasUserId && ! $match->allowsShooter(null)) {
            return back()
                ->withInput()
                ->withErrors(['new_shooter_name' =>
                    app(MembershipValidationService::class)->matchEntryDenialMdMessage($match, null)
                        ?? MembershipValidationService::PROVINCIAL_MEMBERS_ONLY_MD_MESSAGE,
                ]);
        }

        // A brand-new shooter is a non-member. Refuse the waive before
        // provisioning a stub, so a rejected tick does not leave an account behind.
        if (! $hasUserId && $waiveLapsed) {
            $newEmail = trim((string) ($validated['new_shooter_email'] ?? ''));
            $existing = app(GuestShooterService::class)->findExisting(
                $validated['new_shooter_name'],
                $newEmail !== '' ? $newEmail : null,
            );
            $previewCategory = $existing
                ? app(MembershipValidationService::class)->classifyRegistrationCategory($existing, $match->match_date)
                : 'non_member';

            if ($previewCategory === 'non_member') {
                return back()
                    ->withInput()
                    ->withErrors(['waive_lapsed_surcharge' => 'The non-member surcharge cannot be waived. It is kept by SAPRF.']);
            }
        }

        if ($hasUserId) {
            $shooter = User::query()->findOrFail($validated['user_id']);

            if (! $match->allowsShooter($shooter)) {
                return back()
                    ->withInput()
                    ->withErrors(['user_id' =>
                        app(MembershipValidationService::class)->matchEntryDenialMdMessage($match, $shooter)
                            ?? MembershipValidationService::PROVINCIAL_MEMBERS_ONLY_MD_MESSAGE,
                    ]);
            }
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
                ->with('info', $shooter->name.' is already registered for this match (entry #'.$existing->id.').');
        }

        // The waive tick only relaxes a lapsed-member surcharge, and only
        // with a written reason. Non-member surcharge is SAPRF money: do
        // not save the entry as if the tick had worked.
        $currentCategory = app(MembershipValidationService::class)
            ->classifyRegistrationCategory($shooter, $match->match_date);

        if ($waiveLapsed && $currentCategory === 'non_member') {
            return back()
                ->withInput()
                ->withErrors(['waive_lapsed_surcharge' => 'The non-member surcharge cannot be waived. It is kept by SAPRF.']);
        }

        $forcedCategory = ($waiveLapsed && $currentCategory === 'lapsed_member') ? 'active_member' : null;

        // "due" leaves the fee unpaid. Someone else chooses their own
        // division and rifle after the email. Adding yourself keeps the
        // division selected on this form. "paid" is cash or EFT already
        // collected, so a division is required and the card fee is zeroed.
        $mustPay = ($validated['collection'] ?? 'paid') === 'due';
        $forSelf = (int) $shooter->id === (int) $request->user()->id;
        $leaveDivisionToShooter = $mustPay && ! $forSelf;

        if (! $leaveDivisionToShooter && empty($validated['division_id'])) {
            return back()
                ->withInput()
                ->withErrors(['division_id' => 'Please choose a division.']);
        }

        $division = $leaveDivisionToShooter ? null : Division::findOrFail($validated['division_id']);
        $divisionSlug = $division?->slug;

        $breakdown = app(RegistrationPricingService::class)
            ->calculateBreakdown(
                $match,
                $shooter,
                $match->match_date ?: now(),
                $divisionSlug,
                $forcedCategory,
                null,
                $mustPay
                    ? RegistrationPricingService::COLLECTED_BY_PLATFORM
                    : RegistrationPricingService::COLLECTED_BY_DIRECTOR,
            );

        if ($mustPay) {
            $gatewayFee = (float) $breakdown['gateway_fee'];
            $mdNet = (float) $breakdown['md_net'];
            $paymentStatus = 'pending';
            $registrationStatus = 'pending';
        } else {
            $gatewayFee = 0.00;
            $mdNet = round(
                (float) $breakdown['total_fee']
                - (float) $breakdown['saprf_fee']
                - (float) $breakdown['platform_fee']
                - (float) $breakdown['surcharge']
                - $gatewayFee,
                2
            );
            $paymentStatus = 'paid';
            $registrationStatus = 'confirmed';
        }

        $registration = MatchRegistration::query()->create([
            'match_id' => $match->id,
            'user_id' => $shooter->id,
            'registered_by_user_id' => $request->user()->id,
            'division_id' => $division?->id,
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
            'payment_status' => $paymentStatus,
            'registration_status' => $registrationStatus,
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
                'collection' => $mustPay ? 'due' : 'paid',
            ]),
        );

        $feeLabel = number_format((float) $registration->fee_amount, 2);
        if ($mustPay) {
            $mailed = $this->notifyShooterFeeStillDue($shooter, $registration);
            $message = $leaveDivisionToShooter
                ? $shooter->name.' added. They have been emailed to choose a division and rifle, then pay R '.$feeLabel.'.'
                : $shooter->name.' added. Payment of R '.$feeLabel.' is still required.';
            if (! $mailed) {
                $message .= ' No email was sent — this account has no deliverable address.';
            }
        } else {
            $message = $shooter->name.' added to the entry list (confirmed, paid).';
        }

        return redirect()
            ->route('matches.edit', $match)
            ->with('success', $message);
    }

    private function notifyShooterFeeStillDue(User $shooter, MatchRegistration $registration): bool
    {
        if (! $this->shooterHasDeliverableEmail($shooter)) {
            return false;
        }

        try {
            $shooter->notify(new EntryPaymentRequiredNotification($registration));
        } catch (\Throwable $e) {
            Log::warning('Failed to send unpaid entry notification', [
                'registration_id' => $registration->id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }

        return true;
    }

    private function shooterHasDeliverableEmail(User $shooter): bool
    {
        $email = strtolower(trim((string) $shooter->email));
        if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return false;
        }

        return ! str_ends_with($email, '@import.saprf.local')
            && ! str_contains($email, '@saprf.walkin');
    }

    // ── API ──

    public function apiUpcoming(): JsonResponse
    {
        $columns = ['id', 'name', 'slug', 'match_type', 'series_level', 'province_id', 'match_date', 'match_end_date', 'venue_name', 'venue_location', 'city', 'status', 'active_member_fee', 'non_member_fee', 'max_competitors', 'registration_close_date'];

        // Matches that fall on today — lead the homepage with these so a
        // weekend national doesn't disappear from the home panel just because
        // registration has closed or the clock has ticked past midnight.
        $live = MatchEvent::query()
            ->published()
            ->live()
            ->with('province')
            ->withCount('scores')
            ->orderBy('match_date')
            ->get($columns);

        $liveIds = $live->pluck('id')->all();

        // Future matches: start date (or end date, for multi-day) is today or
        // later. Include status=closed so a registration-closed weekend match
        // still appears on the homepage until it has actually finished.
        $today = now()->startOfDay()->toDateString();

        $upcoming = MatchEvent::query()
            ->published()
            ->with('province')
            ->whereNotIn('id', $liveIds)
            ->whereIn('status', ['open', 'closed'])
            ->where(function ($q) use ($today) {
                $q->where('match_date', '>=', $today)
                    ->orWhere(function ($q2) use ($today) {
                        $q2->whereNotNull('match_end_date')
                            ->where('match_end_date', '>=', $today);
                    });
            })
            ->orderBy('match_date')
            ->limit(6)
            ->get($columns);

        return response()->json([
            'data' => $upcoming,
            'live' => $live,
        ]);
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
     * Score exports (CSV / PDF) are MD + staff only. This gate is called by
     * publicExportCsv() and publicExportPdf() after the draft/published
     * check, so unauthorised callers get the same 404 as a non-public
     * match — the URL stays effectively invisible to the public rather
     * than returning a 403 that confirms the resource exists.
     *
     * Keep the role list in sync with the $viewerCanDownloadResults block
     * in publicShow() — the UI button and the endpoint must agree on who
     * can actually use it.
     */
    private function abortIfNotAllowedToDownloadResults(Request $request, MatchEvent $match): void
    {
        $viewer = $request->user();

        $allowed = $viewer !== null && (
            $viewer->hasRole(['owner', 'admin', 'exco', 'developer'])
            || $match->created_by === $viewer->id
        );

        if (! $allowed) {
            abort(404);
        }
    }

    /**
     * Portal entry is blocked while the shooter still owes an entry fee
     * for a match whose date has already arrived. An unpaid entry for a
     * future match does not block.
     */
    private function redirectIfOutstandingBlocksEntry(User $shooter, User $actor): ?RedirectResponse
    {
        if (! $shooter->exists || ! $shooter->id) {
            return null;
        }

        $outstanding = MatchRegistration::firstOutstandingForUser($shooter->id);
        if (! $outstanding) {
            return null;
        }

        return redirect()
            ->route('registrations.show', $outstanding)
            ->with('error', $outstanding->outstandingBlocksNewEntryMessage($shooter->id === $actor->id));
    }

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
    private function resolveShooter(User $actor, ?string $forUserId): User
    {
        if (! $forUserId || (string) $forUserId === (string) $actor->id) {
            return $actor;
        }

        $target = User::query()
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

    private function isManagedShooter(User $shooter, User $parent): bool
    {
        return $shooter->id !== $parent->id
            && $shooter->is_managed_account
            && $shooter->parent_id === $parent->id;
    }
}
