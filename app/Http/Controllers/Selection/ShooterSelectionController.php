<?php

namespace App\Http\Controllers\Selection;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreShooterEligibilityFormRequest;
use App\Models\SelectionAthlete;
use App\Models\SelectionCycle;
use App\Models\SelectionDeclaration;
use App\Models\User;
use App\Notifications\SelectionDeclarationSubmittedNotification;
use App\Services\AuditLogService;
use App\Services\Selection\EligibilityEvaluator;
use App\Services\Selection\ParticipationEvaluator;
use App\Services\Selection\SelectionAthleteStateService;
use App\Services\Selection\SelectionCriteriaStatus;
use App\Services\StaffInboxService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

/**
 * Shooter-facing entry point for IPRF team selection.
 *
 * Every logged-in member can visit /iprf regardless of role. The page lists
 * every currently-open cycle and lets the shooter:
 *   1. Opt in to be considered (creates a SelectionAthlete in state `registered`).
 *   2. Submit the combined DEC-01 intention + Eligibility-to-Compete
 *      attestation form. Submission is what "received by ExCo" means for the
 *      online path — ELG-05 (PR22) / ELG-06 (PRS) flip to PASS without any
 *      further staff action.
 *   3. See their own live ELG / PART status computed by SelectionCriteriaStatus,
 *      matching the numbers ExCo sees on the admin page.
 *
 * Frozen / closed cycles are read-only; writes are refused with a 403. Staff
 * still retain the legacy admin declaration form for paper submissions.
 */
class ShooterSelectionController extends Controller
{
    public function __construct(
        private readonly AuditLogService $audit,
        private readonly EligibilityEvaluator $elg,
        private readonly ParticipationEvaluator $part,
        private readonly SelectionAthleteStateService $state,
        private readonly SelectionCriteriaStatus $criteria,
    ) {}

    public function index(Request $request): View
    {
        $user = $request->user();

        $cycles = SelectionCycle::query()
            ->whereIn('status', ['open', 'frozen', 'announced'])
            ->with(['activePolicy'])
            ->orderBy('series')
            ->orderBy('season')
            ->get();

        $entries = $cycles->map(function (SelectionCycle $cycle) use ($user) {
            $athlete = SelectionAthlete::forCycle($cycle->id)
                ->where('user_id', $user->id)
                ->with(['declaration', 'participationSnapshot', 'cycle.activePolicy', 'user.membership', 'user.club', 'claimedDivision'])
                ->first();

            $criteria = $athlete ? $this->criteria->for($athlete) : null;

            return [
                'cycle' => $cycle,
                'athlete' => $athlete,
                'criteria' => $criteria,
                'is_writable' => $this->isWritable($cycle),
                'has_submitted_form' => $athlete?->declaration?->status === SelectionDeclaration::STATUS_SUBMITTED,
                'profile_complete' => $this->profileIsComplete($user),
            ];
        });

        return view('selection.shooter.index', [
            'user' => $user,
            'entries' => $entries,
        ]);
    }

    public function optIn(Request $request, SelectionCycle $cycle): RedirectResponse
    {
        $this->abortUnlessWritable($cycle);
        $user = $request->user();

        $athlete = SelectionAthlete::forCycle($cycle->id)
            ->where('user_id', $user->id)
            ->first();

        if ($athlete) {
            return redirect()->route('iprf.index')
                ->with('info', 'You are already on the athlete list for this cycle.');
        }

        $athlete = SelectionAthlete::create([
            'selection_cycle_id' => $cycle->id,
            'user_id' => $user->id,
            'state' => SelectionAthlete::STATE_REGISTERED,
        ]);

        $this->audit->log(
            $user,
            'selection_athlete_self_opted_in',
            'SelectionAthlete',
            $athlete->id,
            null,
            [
                'user_id' => $user->id,
                'cycle_id' => $cycle->id,
                'series' => $cycle->series,
                'season' => $cycle->season,
            ],
            "Self opt-in by user #{$user->id} for cycle {$cycle->series} {$cycle->season}",
        );

        return redirect()->route('iprf.index')
            ->with('success', "You are now on the list for consideration in the {$cycle->series} {$cycle->season} cycle. Complete the Eligibility to Compete form below to progress.");
    }

    public function withdraw(Request $request, SelectionCycle $cycle): RedirectResponse
    {
        $this->abortUnlessWritable($cycle);
        $user = $request->user();

        $athlete = SelectionAthlete::forCycle($cycle->id)
            ->where('user_id', $user->id)
            ->firstOrFail();

        $old = [
            'state' => $athlete->state,
            'declaration_status' => $athlete->declaration?->status,
        ];

        // Withdraw the declaration if one was submitted so future re-evaluation
        // does not still count them as declared. Leave the athlete row so the
        // audit trail persists.
        if ($athlete->declaration) {
            $athlete->declaration->update([
                'status' => SelectionDeclaration::STATUS_WITHDRAWN,
            ]);
        }

        $athlete->forceFill([
            'state' => SelectionAthlete::STATE_NOT_SELECTED,
            'manual_eligibility_notes' => trim(($athlete->manual_eligibility_notes ? $athlete->manual_eligibility_notes."\n\n" : '')
                .'Withdrawn by shooter on '.now()->format('Y-m-d H:i').'.'),
        ])->save();

        $this->audit->log(
            $user,
            'selection_athlete_self_withdrew',
            'SelectionAthlete',
            $athlete->id,
            $old,
            [
                'state' => $athlete->state,
                'declaration_status' => $athlete->declaration?->fresh()->status,
            ],
            "Shooter withdrew from cycle {$cycle->series} {$cycle->season}",
        );

        return redirect()->route('iprf.index')
            ->with('success', 'You have withdrawn from this cycle. Contact ExCo if you change your mind.');
    }

    public function storeForm(StoreShooterEligibilityFormRequest $request, SelectionCycle $cycle): RedirectResponse
    {
        $this->abortUnlessWritable($cycle);
        $user = $request->user();

        if (! $this->profileIsComplete($user)) {
            return redirect()->route('profile')
                ->with('error', 'Please complete your citizenship and country of residence before submitting the Eligibility to Compete form.');
        }

        // Auto-register on first form submit so a shooter who came straight to
        // the form (e.g. via an email link) does not need a separate opt-in
        // step. The audit log still shows both actions distinctly.
        $athlete = SelectionAthlete::forCycle($cycle->id)
            ->where('user_id', $user->id)
            ->first();

        if (! $athlete) {
            $athlete = SelectionAthlete::create([
                'selection_cycle_id' => $cycle->id,
                'user_id' => $user->id,
                'state' => SelectionAthlete::STATE_REGISTERED,
            ]);

            $this->audit->log(
                $user,
                'selection_athlete_self_opted_in',
                'SelectionAthlete',
                $athlete->id,
                null,
                [
                    'user_id' => $user->id,
                    'cycle_id' => $cycle->id,
                    'series' => $cycle->series,
                    'season' => $cycle->season,
                    'via' => 'form_submit',
                ],
                "Auto opt-in on form submit by user #{$user->id}",
            );
        }

        $signature = trim((string) $request->input('signature'));
        if (mb_strtolower($signature) !== mb_strtolower(trim((string) $user->name))) {
            return back()
                ->withErrors(['signature' => 'Your typed signature must match the name on your account ('.$user->name.').'])
                ->withInput();
        }

        $formData = [
            // ELG-05 / ELG-06 boolean read by the ruleset. Submission = received.
            'eligibility_to_compete_received' => true,
            'received_channel' => 'online_form',
            'received_at' => now()->toIso8601String(),
            'attestations' => [
                'intention_to_participate' => true,
                'able_and_willing' => true,
                'satisfy_preconditions' => true,
                'no_impairment' => true,
            ],
            'signature' => $signature,
            'notes' => $request->input('notes'),
            'submitted_by_user_id' => $user->id,
            'submitted_ip' => $request->ip(),
        ];

        $existing = $athlete->declaration;
        $old = $existing ? $existing->only(['status', 'submitted_at', 'form_data']) : null;

        $declaration = SelectionDeclaration::updateOrCreate(
            ['selection_athlete_id' => $athlete->id],
            [
                'submitted_at' => now(),
                'captured_by' => $user->id,
                'form_data' => $formData,
                'status' => SelectionDeclaration::STATUS_SUBMITTED,
            ],
        );

        $this->audit->log(
            $user,
            'selection_declaration_submitted_online',
            'SelectionDeclaration',
            $declaration->id,
            $old,
            [
                'selection_athlete_id' => $athlete->id,
                'cycle_id' => $cycle->id,
                'series' => $cycle->series,
                'season' => $cycle->season,
                'status' => $declaration->status,
                'submitted_at' => $declaration->submitted_at?->toIso8601String(),
                'attestations' => $formData['attestations'],
                'signature' => $signature,
                'received_channel' => 'online_form',
            ],
            "Online Eligibility to Compete form submitted by user #{$user->id}",
        );

        // Re-run the evaluators + state machine so the ELG form rule flips to
        // PASS immediately and the athlete moves out of `registered` if their
        // membership / citizenship data now supports it.
        $this->elg->evaluate($athlete->fresh(['user.membership', 'user.club', 'cycle.activePolicy', 'declaration']));
        $this->part->evaluate($athlete->fresh(['user.membership', 'user.club', 'cycle.activePolicy', 'declaration']));
        $this->state->recompute($athlete->fresh(['cycle.activePolicy', 'declaration']));

        $this->notifyExco($athlete->fresh(['user', 'cycle', 'declaration']));

        return redirect()->route('iprf.index')
            ->with('success', 'Your Eligibility to Compete form has been submitted and recorded with ExCo. Thank you.');
    }

    /**
     * Cycles are only writable while `open`. Once ExCo freezes results or
     * closes the cycle, opt-in / withdrawal / form submissions all stop.
     */
    private function isWritable(SelectionCycle $cycle): bool
    {
        return $cycle->status === 'open';
    }

    private function abortUnlessWritable(SelectionCycle $cycle): void
    {
        abort_unless($this->isWritable($cycle), 403, 'This selection cycle is no longer accepting submissions.');
    }

    /**
     * We need SA citizenship + country of residence before we can meaningfully
     * evaluate ELG-02 / ELG-04. Blocking form submission on missing data keeps
     * the audit trail honest — we never store an attestation from a shooter
     * whose eligibility we could not check.
     */
    private function profileIsComplete(User $user): bool
    {
        return $user->sa_citizen !== null && filled($user->country_of_residence);
    }

    private function notifyExco(SelectionAthlete $athlete): void
    {
        try {
            app(StaffInboxService::class)->notify(
                new SelectionDeclarationSubmittedNotification($athlete),
                ['developer', 'owner', 'exco'],
            );
        } catch (\Throwable $e) {
            // Delivery failure never invalidates the submission: the row is
            // saved, the audit log is written, and the admin page shows the
            // form immediately.
            Log::warning('IPRF form submission notification failed: '.$e->getMessage(), [
                'selection_athlete_id' => $athlete->id,
            ]);
        }
    }
}
