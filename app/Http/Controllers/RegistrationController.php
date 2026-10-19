<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreRegistrationRequest;
use App\Models\AccountCredit;
use App\Models\MatchEvent;
use App\Models\MatchRegistration;
use App\Notifications\MatchRegistrationConfirmedNotification;
use App\Notifications\PaymentInquiryNotification;
use App\Services\AccountCreditService;
use App\Services\AuditLogService;
use App\Services\CreditRefundService;
use App\Services\QualificationService;
use App\Services\RegistrationPricingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class RegistrationController extends Controller
{
    public function __construct(
        private readonly RegistrationPricingService $pricingService,
        private readonly AuditLogService $auditLogService,
        private readonly AccountCreditService $accountCreditService,
        private readonly CreditRefundService $creditRefundService,
    ) {}

    public function index(Request $request): View
    {
        $user = $request->user();
        // Staff can flip to shooter view via the sidebar toggle. When they do,
        // this page must behave like it would for a pure member: only their
        // own entries, and no financial columns. Roles alone aren't enough —
        // we also require the effective view mode to be `admin`.
        $isPrivileged = $user->hasAnyRole(['developer', 'exco', 'owner', 'admin', 'match_director'])
            && $user->effectiveViewMode() === 'admin';

        $matchId = $request->integer('match_id') ?: null;
        $match = $matchId ? MatchEvent::find($matchId) : null;

        $paymentStatus = $request->string('payment_status')->toString();
        $registrationStatus = $request->string('registration_status')->toString();
        $search = trim($request->string('search')->toString());

        if ($match) {
            // Public entry list for a single match — everyone can see who has
            // registered. Cancelled/withdrawn entries are hidden.
            $registrations = $match->registrations()
                ->where('registration_status', '!=', 'cancelled')
                ->with(['match', 'user', 'division'])
                ->orderBy('registered_at')
                ->paginate(50)
                ->withQueryString();
        } elseif ($isPrivileged) {
            $query = MatchRegistration::query()->with(['match', 'user', 'division']);

            if ($paymentStatus !== '') {
                $query->where('payment_status', $paymentStatus);
            }

            if ($registrationStatus !== '') {
                $query->where('registration_status', $registrationStatus);
            }

            if ($search !== '') {
                $query->where(function ($q) use ($search) {
                    $q->where('shooter_name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%")
                        ->orWhereHas('user', fn ($u) => $u->where('name', 'like', "%{$search}%")
                            ->orWhere('email', 'like', "%{$search}%"));
                });
            }

            $registrations = $query->latest()->paginate(20)->withQueryString();
        } else {
            $registrations = $user->matchRegistrations()->with(['match', 'user', 'division'])->latest()->paginate(20);
        }

        // Fee/payment columns stay restricted to organisers; the entry list
        // itself (names + category + status) is visible to everyone.
        $canViewFinancials = $isPrivileged;

        // Only privileged admin-mode users see the filter bar.
        $showFilters = $isPrivileged && ! $match;

        // Match dropdown for the filter bar — recent matches first so the
        // list stays short even once we've built up years of entries.
        $matchOptions = $showFilters
            ? MatchEvent::query()->orderByDesc('match_date')->limit(100)->get(['id', 'name', 'match_date'])
            : collect();

        $filters = [
            'payment_status' => $paymentStatus,
            'registration_status' => $registrationStatus,
            'search' => $search,
        ];

        return view('registrations.index', compact(
            'registrations',
            'match',
            'canViewFinancials',
            'isPrivileged',
            'showFilters',
            'matchOptions',
            'filters',
        ));
    }

    public function store(StoreRegistrationRequest $request): RedirectResponse
    {
        $user = $request->user();
        $match = MatchEvent::query()->findOrFail($request->validated('match_id'));

        $outstanding = MatchRegistration::firstOutstandingForUser($user->id);
        if ($outstanding) {
            return redirect()
                ->route('registrations.show', $outstanding)
                ->with('error', $outstanding->outstandingBlocksNewEntryMessage(true));
        }

        $champsEntry = app(QualificationService::class)->championshipEntryStatus($user, $match);
        if (! $champsEntry['eligible']) {
            return back()->with('error', sprintf(
                'You can’t enter the championships yet. The SAPRF rules require 1 national and 1 provincial %s match first (nationals shot: %d, provincials shot: %d).',
                $match->series,
                $champsEntry['national'],
                $champsEntry['provincial'],
            ));
        }

        $breakdown = $this->pricingService->calculateBreakdown($match, $user, $match->match_date);

        $registration = MatchRegistration::query()->create([
            'match_id' => $match->id,
            'user_id' => $user->id,
            'shooter_name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'membership_fee_category' => $breakdown['category'],
            'fee_amount' => $breakdown['total_fee'],
            'surcharge_amount' => $breakdown['surcharge'],
            'saprf_fee' => $breakdown['saprf_fee'],
            'platform_fee' => $breakdown['platform_fee'],
            'gateway_fee' => $breakdown['gateway_fee'],
            'md_net_amount' => $breakdown['md_net'],
            'payment_status' => 'unpaid',
            'registration_status' => 'pending',
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

        try {
            $user->notify(new MatchRegistrationConfirmedNotification($registration));
        } catch (\Throwable $e) {
            Log::warning('Failed to send match registration notification', ['error' => $e->getMessage()]);
        }

        return redirect()->route('registrations.show', $registration)
            ->with('success', 'Registration submitted successfully.');
    }

    public function show(Request $request, MatchRegistration $registration): View
    {
        $this->authorize('view', $registration);

        $registration->load([
            'match',
            'user',
            'registeredBy',
            'division',
            'rifleConfiguration.make',
            'rifleConfiguration.model',
            'rifleConfiguration.calibre',
        ]);

        // Payer for the confirmed entry (if any). Ordered so the completed payment wins
        // over stale pending rows created when checkout was abandoned then retried.
        $payerPayment = $registration->payments()
            ->with('user')
            ->orderByRaw("CASE WHEN status = 'completed' THEN 0 ELSE 1 END")
            ->latest('id')
            ->first();
        $payer = $payerPayment?->user;

        // Expose the invoiceable payment (completed, non-zero) to the view
        // so it can render a "Download invoice" button. Pending / failed
        // payments are intentionally hidden — there's nothing to invoice.
        $invoicePayment = $payerPayment && $payerPayment->status === 'completed' && (float) $payerPayment->amount > 0
            ? $payerPayment
            : null;

        $rifles = $request->user()->rifleConfigurations()
            ->active()
            ->with(['make', 'model', 'calibre'])
            ->orderMainsFirst($registration->match?->series ?? $registration->match?->match_type)
            ->get();

        $divisions = $registration->match?->availableDivisions() ?? collect();

        return view('registrations.show', compact('registration', 'rifles', 'divisions', 'payer', 'invoicePayment'));
    }

    public function updateRifle(Request $request, MatchRegistration $registration): RedirectResponse
    {
        if ($registration->user_id !== $request->user()->id && ! $request->user()->hasAnyRole(['owner', 'admin', 'match_director'])) {
            abort(403);
        }

        $validated = $request->validate([
            'rifle_configuration_id' => ['nullable', 'exists:rifle_configurations,id'],
        ]);

        $old = $registration->only(['rifle_configuration_id']);
        $registration->update($validated);

        $this->auditLogService->log(
            $request->user(),
            'registration.rifle.updated',
            'MatchRegistration',
            $registration->id,
            $old,
            $validated,
        );

        return redirect()->route('registrations.show', $registration)
            ->with('success', 'Rifle configuration updated.');
    }

    /**
     * Change the division a shooter is entered under. Allowed for the shooter
     * (or staff) up until registration closes.
     */
    public function updateDivision(Request $request, MatchRegistration $registration): RedirectResponse
    {
        $isStaff = $request->user()->hasAnyRole(['owner', 'admin', 'match_director']);

        if ($registration->user_id !== $request->user()->id && ! $isStaff) {
            abort(403);
        }

        // Members can only change their entry while registration is open; staff
        // may adjust it at any time.
        if (! $isStaff && ! $registration->canEditEntry()) {
            return back()->with('error', 'Registration has closed — the division can no longer be changed.');
        }

        $allowedDivisionIds = $registration->match?->availableDivisions()->pluck('id')->all() ?? [];

        $validated = $request->validate([
            'division_id' => ['required', \Illuminate\Validation\Rule::in($allowedDivisionIds)],
        ], [
            'division_id.required' => 'Please choose a division.',
            'division_id.in' => 'The selected division is not available for this match.',
        ]);

        $old = $registration->only(['division_id']);
        $registration->update($validated);

        $this->auditLogService->log(
            $request->user(),
            'registration.division.updated',
            'MatchRegistration',
            $registration->id,
            $old,
            $validated,
        );

        return redirect()->route('registrations.show', $registration)
            ->with('success', 'Division updated.');
    }

    public function updateShotCount(Request $request, MatchRegistration $registration): RedirectResponse
    {
        if ($registration->user_id !== $request->user()->id && ! $request->user()->hasAnyRole(['owner', 'admin', 'match_director'])) {
            abort(403);
        }

        $validated = $request->validate([
            'shot_count' => ['nullable', 'integer', 'min:0', 'max:9999'],
        ]);

        $old = $registration->only(['shot_count']);
        $registration->update($validated);

        if ($registration->rifle_configuration_id) {
            $registration->rifleConfiguration->recalculateShotCount();
        }

        $this->auditLogService->log(
            $request->user(),
            'registration.shots.updated',
            'MatchRegistration',
            $registration->id,
            $old,
            $validated,
        );

        return redirect()->route('registrations.show', $registration)
            ->with('success', 'Shot count updated.');
    }

    public function updateStatus(Request $request, MatchRegistration $registration): RedirectResponse
    {
        $this->authorize('update', $registration);

        $validated = $request->validate([
            'registration_status' => ['required', 'in:confirmed,cancelled,pending,waitlisted'],
        ]);

        $isCancelling = $validated['registration_status'] === 'cancelled'
            && $registration->registration_status !== 'cancelled';
        $creditsPayer = $isCancelling && $registration->hasCompletedPlatformPayment();

        if ($registration->registration_status === 'cancelled'
            && $validated['registration_status'] !== 'cancelled'
            && $registration->hasIssuedCredit()) {
            return back()->with('error', 'This entry was already credited back to the payer\'s account, so it cannot be reinstated. Enter the shooter again instead.');
        }

        $request->validate([
            'cancellation_reason' => [Rule::requiredIf($creditsPayer), 'nullable', 'string', 'min:5', 'max:500'],
        ], [
            'cancellation_reason.required' => 'A reason is required when removing a paid shooter — it is included in the credit email.',
        ]);
        $reason = trim((string) $request->input('cancellation_reason', ''));

        $old = $registration->only(['registration_status', 'cancelled_at', 'cancellation_reason']);

        $credit = DB::transaction(function () use ($registration, $validated, $isCancelling, $creditsPayer, $reason, $request, $old) {
            $registration->update([
                'registration_status' => $validated['registration_status'],
                'cancelled_at' => match (true) {
                    $isCancelling => now(),
                    $validated['registration_status'] !== 'cancelled' => null,
                    default => $registration->cancelled_at,
                },
                'cancellation_reason' => $isCancelling && $reason !== ''
                    ? $reason
                    : $registration->cancellation_reason,
            ]);

            $this->auditLogService->log(
                $request->user(),
                'registration.status.updated',
                'MatchRegistration',
                $registration->id,
                $old,
                $registration->only(['registration_status', 'cancelled_at', 'cancellation_reason']),
            );

            return $creditsPayer
                ? $this->accountCreditService->issueForRemovedRegistration($registration, $reason, $request->user())
                : null;
        });

        $message = $credit
            ? 'Shooter removed. R '.number_format((float) $credit->amount, 2).' credited to '.($credit->user?->name ?? 'the payer').'\'s account.'
            : 'Registration status updated.';

        return redirect()->route('registrations.show', $registration)
            ->with('success', $message);
    }

    /**
     * Staff correction: move an unpaid entry to a different fee bracket
     * (e.g. lapsed → active) and rebuild the fee row so the surcharge
     * drops. Refused once payment has been collected.
     */
    public function updateCategory(Request $request, MatchRegistration $registration): RedirectResponse
    {
        $this->authorize('update', $registration);

        if (! $registration->canCorrectCategory()) {
            return back()->with('error', 'The fee category cannot be changed after payment has been collected.');
        }

        $validated = $request->validate([
            'membership_fee_category' => ['required', Rule::in(RegistrationPricingService::CATEGORIES)],
            'fee_override_reason' => ['required', 'string', 'min:5', 'max:500'],
        ]);

        $result = $this->pricingService->applyCategory(
            $registration,
            $validated['membership_fee_category'],
            $validated['fee_override_reason'],
        );

        $this->auditLogService->log(
            $request->user(),
            'registration.category.updated',
            'MatchRegistration',
            $registration->id,
            $result['old'],
            $result['new'],
            $validated['fee_override_reason'],
        );

        $registration->refresh();

        return redirect()->route('registrations.show', $registration)
            ->with(
                'success',
                'Category updated to '.$registration->feeCategoryLabel()
                .'. Fee is now R '.number_format((float) $registration->fee_amount, 2).'.'
            );
    }

    public function withdraw(Request $request, MatchRegistration $registration): RedirectResponse
    {
        $user = $request->user();

        if ($registration->user_id !== $user->id) {
            abort(403, 'You can only withdraw your own registration.');
        }

        if (! $registration->isWithdrawable()) {
            return back()->with('error', 'This registration cannot be withdrawn.');
        }

        $refundCalc = $registration->calculateRefund();
        $hasChoice = in_array($refundCalc['reason'], ['before_deadline', 'paid_offline'], true);
        $canTakeCash = $refundCalc['reason'] === 'before_deadline'
            && $refundCalc['refund'] > 0
            && $registration->platformPayerId() === $user->id;

        $validated = $request->validate([
            'cancellation_reason' => ['nullable', 'string', 'max:500'],
            'withdrawal_option' => [
                Rule::requiredIf($hasChoice),
                'nullable',
                Rule::in($canTakeCash ? ['credit', 'refund'] : ['credit']),
            ],
        ], [
            'withdrawal_option.required' => 'Choose whether to keep the money as credit or have it refunded.',
            'withdrawal_option.in' => 'A cash refund is not available for this entry — keep it as credit instead.',
        ]);

        $option = $hasChoice ? $validated['withdrawal_option'] : null;
        $bank = $option === 'refund'
            ? CreditRefundService::normaliseBankDetails($request->validate(CreditRefundService::bankDetailRules()))
            : null;

        $split = $option === 'refund' ? $refundCalc['split'] : ['platform' => 0, 'gateway' => 0, 'saprf' => 0];
        $old = $registration->only(['registration_status', 'payment_status']);

        $credit = DB::transaction(function () use ($registration, $user, $validated, $refundCalc, $option, $split, $bank, $old) {
            $registration->update([
                'registration_status' => 'cancelled',
                'cancelled_at' => now(),
                'cancellation_reason' => $validated['cancellation_reason'] ?? null,
                'withdrawal_option' => $option,
                'refund_amount' => $option === 'credit' ? $refundCalc['credit'] : $refundCalc['refund'],
                'admin_fee_charged' => $option === 'credit' ? 0 : $refundCalc['admin_fee'],
                'penalty_platform_fee' => $split['platform'],
                'penalty_gateway_fee' => $split['gateway'],
                'penalty_saprf_fee' => $split['saprf'],
            ]);

            $this->auditLogService->log(
                $user,
                'registration.withdrawn',
                'MatchRegistration',
                $registration->id,
                $old,
                [
                    'registration_status' => 'cancelled',
                    'withdrawal_option' => $option,
                    'refund_amount' => (float) $registration->refund_amount,
                    'admin_fee_charged' => (float) $registration->admin_fee_charged,
                    'penalty_split' => $split,
                    'reason' => $refundCalc['reason'],
                ],
            );

            return match ($option) {
                'credit' => $this->accountCreditService->issueForWithdrawal($registration, $user),
                'refund' => $this->creditRefundService->requestForWithdrawal($registration, $user, $refundCalc['refund'], $bank),
                default => null,
            };
        });

        $message = match (true) {
            $refundCalc['reason'] === 'free_entry' => 'Registration withdrawn. This was a free entry — no financial impact.',
            $refundCalc['reason'] === 'unpaid' => 'Registration withdrawn. No payment was collected, so no refund is due.',
            $refundCalc['reason'] === 'past_deadline' => 'Registration withdrawn. No refund — withdrawal was after the deadline.',
            $option === 'credit' && $credit instanceof AccountCredit => 'Registration withdrawn. R '.number_format((float) $credit->amount, 2).' is on '
                .($credit->user_id === $user->id ? 'your' : ($credit->user?->name ?? 'the payer').'\'s').' account as entry credit.',
            $option === 'refund' => 'Registration withdrawn. R '.number_format($refundCalc['refund'], 2).' will be refunded to your bank account (R '
                .number_format($refundCalc['admin_fee'], 2).' withdrawal fee deducted).',
            default => 'Registration withdrawn.',
        };

        return redirect()->route('registrations.show', $registration)
            ->with('success', $message);
    }

    /**
     * MD/admin action: email the shooter about an outstanding entry fee.
     * The mail offers PayFast "Pay now", or a reply with POP / PayFast
     * confirmation screenshot so staff can mark the row settled.
     *
     * We refuse to nudge:
     *   - Already-settled rows (paid / waived) — nothing to chase.
     *   - Free entries — asking for R 0 looks broken.
     *   - Rows we sent an inquiry to less than 24h ago — dedupes an
     *     accidental double-click.
     *
     * Auth: {@see \App\Policies\RegistrationPolicy::update} — admins,
     * owners, and the MD of the registration's match.
     */
    public function sendPaymentInquiry(Request $request, MatchRegistration $registration): RedirectResponse
    {
        $this->authorize('update', $registration);

        if (! $registration->hasOutstandingPayment()) {
            return back()->with('error', 'This entry has no outstanding fee — nothing to chase.');
        }

        if (! $registration->canSendPaymentInquiry()) {
            $ago = $registration->payment_inquiry_sent_at?->diffForHumans() ?? 'recently';

            return back()->with('error', 'A payment inquiry was already sent ' . $ago . ' — please wait 24h before re-sending.');
        }

        $shooter = $registration->user;
        if (! $shooter || ! $shooter->email) {
            return back()->with('error', 'Cannot email this shooter — no email address on file.');
        }

        try {
            $shooter->notify(new PaymentInquiryNotification($registration, $request->user()));
        } catch (\Throwable $e) {
            Log::warning('Failed to queue payment inquiry notification', [
                'registration_id' => $registration->id,
                'error' => $e->getMessage(),
            ]);

            return back()->with('error', 'Failed to send the email. Please try again shortly.');
        }

        $registration->update(['payment_inquiry_sent_at' => now()]);

        $this->auditLogService->log(
            $request->user(),
            'registration.payment_inquiry.sent',
            'MatchRegistration',
            $registration->id,
            null,
            [
                'match_id' => $registration->match_id,
                'shooter_email' => $shooter->email,
                'fee_amount' => (float) $registration->fee_amount,
            ],
        );

        return back()->with(
            'success',
            'Payment inquiry emailed to ' . $shooter->name . ' (' . $shooter->email . ').'
        );
    }

    /**
     * MD/admin marks an outstanding entry as settled after receiving POP
     * or a PayFast confirmation screenshot by email. Does not create a
     * PayFast Payment row — money was collected off this ITN path.
     */
    public function markPaymentSettled(Request $request, MatchRegistration $registration): RedirectResponse
    {
        $this->authorize('update', $registration);

        if (! $registration->hasOutstandingPayment()) {
            return back()->with('error', 'This entry is already settled.');
        }

        $validated = $request->validate([
            'settlement_proof' => ['required', 'in:pop,payfast_screenshot,other'],
            'settlement_note' => ['required', 'string', 'min:5', 'max:500'],
        ], [
            'settlement_proof.required' => 'Choose how payment was proven (POP, PayFast screenshot, or other).',
            'settlement_note.required' => 'Add a short note (e.g. “POP received 19 Oct — EFT to range”).',
        ]);

        $proofLabel = match ($validated['settlement_proof']) {
            'pop' => 'POP',
            'payfast_screenshot' => 'PayFast confirmation screenshot',
            default => 'Other proof',
        };

        $old = $registration->only(['payment_status', 'registration_status', 'fee_override_reason', 'gateway_fee', 'md_net_amount']);

        $note = trim(
            ($registration->fee_override_reason ? $registration->fee_override_reason.' | ' : '')
            .'Settled by '.$request->user()->name.' via '.$proofLabel.': '.$validated['settlement_note']
        );

        // POP / "other" settlement means the money reached the MD outside
        // PayFast (cash on the range, direct EFT, etc.) — so the gateway
        // estimate booked at register-time is a phantom cost that silently
        // shaves ~R20 off the MD's payout. Zero it out and credit the MD
        // back the same amount so the payout matches what was actually
        // collected. "payfast_screenshot" is the one proof type that
        // retains the gateway fee: the money DID go through PayFast, we
        // just never got the ITN that would have booked it automatically.
        $gatewayRefund = 0.0;
        $newGatewayFee = (float) $registration->gateway_fee;
        $newMdNet = (float) $registration->md_net_amount;
        if ($validated['settlement_proof'] !== 'payfast_screenshot' && $newGatewayFee > 0) {
            $gatewayRefund = $newGatewayFee;
            $newGatewayFee = 0.0;
            $newMdNet = round($newMdNet + $gatewayRefund, 2);
        }

        $registration->update([
            'payment_status' => 'paid',
            'registration_status' => $registration->registration_status === 'pending'
                ? 'confirmed'
                : $registration->registration_status,
            'fee_override_reason' => $note,
            'gateway_fee' => $newGatewayFee,
            'md_net_amount' => $newMdNet,
        ]);

        $this->auditLogService->log(
            $request->user(),
            'registration.payment.manually_settled',
            'MatchRegistration',
            $registration->id,
            $old,
            [
                'payment_status' => 'paid',
                'registration_status' => $registration->registration_status,
                'settlement_proof' => $validated['settlement_proof'],
                'settlement_note' => $validated['settlement_note'],
                'gateway_fee' => $newGatewayFee,
                'md_net_amount' => $newMdNet,
                'gateway_refunded_to_md' => $gatewayRefund,
            ],
        );

        $shooterLabel = $registration->user?->name ?? $registration->shooter_name ?? 'shooter';

        $message = 'Marked '.$shooterLabel.' as paid ('.$proofLabel.').';
        if ($gatewayRefund > 0) {
            $message .= ' Gateway fee of R'.number_format($gatewayRefund, 2)
                .' credited back to the MD since no PayFast transaction occurred.';
        }

        return back()->with('success', $message);
    }

    /**
     * MD/admin comps an entry — the shooter pays nothing, but SAPRF and the
     * platform still need their cut, so the MD covers it out of their own
     * payout. Same accounting shape as a walk-in: fee_amount zeroed,
     * gateway zeroed (no card), md_net_amount set to -(saprf+platform) so
     * the row contributes a negative line to the MD's payout rollup.
     *
     * Deliberately a separate action from markPaymentSettled: that one says
     * "shooter paid, keep most of the fee"; this one says "no one paid the
     * shooter's side, I'll cover the federation levies myself".
     */
    public function compEntry(Request $request, MatchRegistration $registration): RedirectResponse
    {
        $this->authorize('update', $registration);

        if (! $registration->hasOutstandingPayment()) {
            return back()->with('error', 'This entry is already settled.');
        }

        $validated = $request->validate([
            'comp_note' => ['required', 'string', 'min:5', 'max:500'],
        ], [
            'comp_note.required' => 'Add a short note explaining the comp (e.g. "Travelled from EC, MD covered").',
        ]);

        $saprf = (float) $registration->saprf_fee;
        $platform = (float) $registration->platform_fee;
        // MD owes SAPRF + platform on the shooter's behalf. Stored as a
        // negative md_net so the payout rollup sums it into a deduction.
        $mdDeduction = -1 * round($saprf + $platform, 2);

        $old = $registration->only([
            'payment_status', 'registration_status', 'fee_amount',
            'surcharge_amount', 'gateway_fee', 'md_net_amount',
            'fee_override_reason',
        ]);

        $note = trim(
            ($registration->fee_override_reason ? $registration->fee_override_reason.' | ' : '')
            .'Comped by '.$request->user()->name.' ('.now()->toDateString().'): '.$validated['comp_note']
        );

        $registration->update([
            'payment_status' => 'waived',
            'registration_status' => $registration->registration_status === 'pending'
                ? 'confirmed'
                : $registration->registration_status,
            // Shooter owes nothing — zero the fee, surcharge and gateway so
            // the row doesn't look like money ever flowed through PayFast.
            'fee_amount' => 0.00,
            'surcharge_amount' => 0.00,
            'gateway_fee' => 0.00,
            'md_net_amount' => $mdDeduction,
            'fee_override_reason' => $note,
        ]);

        $this->auditLogService->log(
            $request->user(),
            'registration.comped',
            'MatchRegistration',
            $registration->id,
            $old,
            [
                'payment_status' => 'waived',
                'registration_status' => $registration->registration_status,
                'comp_note' => $validated['comp_note'],
                'md_deduction' => $mdDeduction,
                'saprf_fee' => $saprf,
                'platform_fee' => $platform,
            ],
        );

        $shooterLabel = $registration->user?->name ?? $registration->shooter_name ?? 'shooter';
        $message = 'Comped '.$shooterLabel.'.';
        $owed = abs($mdDeduction);
        if ($owed > 0) {
            $message .= ' R'.number_format($owed, 2).' SAPRF + platform will come off the MD payout.';
        }

        return back()->with('success', $message);
    }

    /**
     * Legacy signed "I paid on the old site" landing — kept for emails
     * already in shooters' inboxes. New inquiries no longer link here.
     *
     * Deliberately a two-step flow — a bare GET must not mutate anything
     * so that link previews / inbox pre-fetchers can't self-confirm.
     */
    public function showOldSitePaymentConfirmation(MatchRegistration $registration): View|RedirectResponse
    {
        $registration->loadMissing('match', 'user');

        // Idempotent: if a previous click already flipped the row to
        // paid/waived, don't ask the shooter to confirm a second time.
        if (! $registration->hasOutstandingPayment()) {
            return redirect()->route('registrations.confirm-old-site-payment.done', [
                'registration' => $registration->id,
            ]);
        }

        return view('registrations.confirm-old-site-payment', compact('registration'));
    }

    /**
     * The shooter clicked "Yes, confirm" on the landing page. Flip the
     * row to `waived` (money didn't come through PayFast, so it's not
     * `paid`; treating it as `waived` keeps the finance totals honest
     * — the row is settled but excluded from platform-collected takings).
     * Also flip the registration status to `confirmed` so downstream
     * views stop showing "Pending" against the shooter's name.
     */
    public function confirmOldSitePayment(Request $request, MatchRegistration $registration): RedirectResponse
    {
        $registration->loadMissing('match', 'user');

        // Same idempotency guard as the GET — protects against a double
        // POST from a nervous shooter clicking Confirm twice.
        if (! $registration->hasOutstandingPayment()) {
            return redirect()->route('registrations.confirm-old-site-payment.done', [
                'registration' => $registration->id,
            ]);
        }

        $old = $registration->only(['payment_status', 'registration_status']);

        $registration->update([
            'payment_status' => 'waived',
            'registration_status' => $registration->registration_status === 'pending'
                ? 'confirmed'
                : $registration->registration_status,
            'fee_override_reason' => trim(
                ($registration->fee_override_reason ? $registration->fee_override_reason . ' | ' : '')
                . 'Shooter self-confirmed payment via legacy SAPRF site (' . now()->toDateString() . ').'
            ),
        ]);

        // Attribute the audit entry to the shooter — they're the one
        // who clicked the signed link. No auth session, so pass null
        // user and record the actor in the metadata payload instead.
        $this->auditLogService->log(
            null,
            'registration.payment.old_site_confirmed',
            'MatchRegistration',
            $registration->id,
            $old,
            [
                'payment_status' => 'waived',
                'registration_status' => $registration->registration_status,
                'confirmed_by_user_id' => $registration->user_id,
                'confirmed_via' => 'signed_email_link',
                'ip' => $request->ip(),
            ],
        );

        return redirect()->route('registrations.confirm-old-site-payment.done', [
            'registration' => $registration->id,
        ]);
    }

    /**
     * Thank-you page shown after {@see confirmOldSitePayment}. Also the
     * "already confirmed" landing when the shooter revisits the signed
     * URL after the fact. Rendered without the signed middleware so a
     * refresh doesn't 403; the page is purely informational.
     */
    public function oldSitePaymentConfirmationDone(MatchRegistration $registration): View
    {
        $registration->loadMissing('match');

        return view('registrations.confirm-old-site-payment-done', compact('registration'));
    }
}
