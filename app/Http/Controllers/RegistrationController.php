<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreRegistrationRequest;
use App\Models\MatchEvent;
use App\Models\MatchRegistration;
use App\Notifications\MatchRegistrationConfirmedNotification;
use App\Notifications\PaymentInquiryNotification;
use App\Services\AuditLogService;
use App\Services\RegistrationPricingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class RegistrationController extends Controller
{
    public function __construct(
        private readonly RegistrationPricingService $pricingService,
        private readonly AuditLogService $auditLogService,
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
            $registrations = MatchRegistration::query()->with(['match', 'user', 'division'])->latest()->paginate(20);
        } else {
            $registrations = $user->matchRegistrations()->with(['match', 'user', 'division'])->latest()->paginate(20);
        }

        // Fee/payment columns stay restricted to organisers; the entry list
        // itself (names + category + status) is visible to everyone.
        $canViewFinancials = $isPrivileged;

        return view('registrations.index', compact('registrations', 'match', 'canViewFinancials', 'isPrivileged'));
    }

    public function store(StoreRegistrationRequest $request): RedirectResponse
    {
        $user = $request->user();
        $match = MatchEvent::query()->findOrFail($request->validated('match_id'));

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
        $payer = $registration->payments()
            ->with('user')
            ->orderByRaw("CASE WHEN status = 'completed' THEN 0 ELSE 1 END")
            ->latest('id')
            ->first()
            ?->user;

        $rifles = $request->user()->rifleConfigurations()
            ->active()
            ->with(['make', 'model', 'calibre'])
            ->orderMainsFirst($registration->match?->series ?? $registration->match?->match_type)
            ->get();

        $divisions = $registration->match?->availableDivisions() ?? collect();

        return view('registrations.show', compact('registration', 'rifles', 'divisions', 'payer'));
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

        $old = $registration->only(['registration_status']);
        $registration->update($validated);

        $this->auditLogService->log(
            $request->user(),
            'registration.status.updated',
            'MatchRegistration',
            $registration->id,
            $old,
            $registration->only(['registration_status']),
        );

        return redirect()->route('registrations.show', $registration)
            ->with('success', 'Registration status updated.');
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

        $request->validate([
            'cancellation_reason' => ['nullable', 'string', 'max:500'],
        ]);

        $refundCalc = $registration->calculateRefund();
        $old = $registration->only(['registration_status', 'payment_status']);

        $registration->update([
            'registration_status' => 'cancelled',
            'cancelled_at' => now(),
            'cancellation_reason' => $request->input('cancellation_reason'),
            'refund_amount' => $refundCalc['refund'],
            'admin_fee_charged' => $refundCalc['admin_fee'],
        ]);

        $this->auditLogService->log(
            $user,
            'registration.withdrawn',
            'MatchRegistration',
            $registration->id,
            $old,
            [
                'registration_status' => 'cancelled',
                'refund_amount' => $refundCalc['refund'],
                'admin_fee_charged' => $refundCalc['admin_fee'],
                'reason' => $refundCalc['reason'],
            ],
        );

        $message = match ($refundCalc['reason']) {
            'free_entry'      => 'Registration withdrawn. This was a free entry — no financial impact.',
            'unpaid'          => 'Registration withdrawn. No payment was collected, so no refund is due.',
            'past_deadline'   => 'Registration withdrawn. No refund — withdrawal was after the deadline.',
            'before_deadline' => $refundCalc['refund'] > 0
                ? 'Registration withdrawn. Refund of R ' . number_format($refundCalc['refund'], 2) . ' (minus R ' . number_format($refundCalc['admin_fee'], 2) . ' admin fee).'
                : 'Registration withdrawn. The admin fee equalled or exceeded the entry fee, so no refund is due.',
            default           => 'Registration withdrawn.',
        };

        return redirect()->route('registrations.show', $registration)
            ->with('success', $message);
    }

    /**
     * MD/admin action: email the shooter about an outstanding entry fee.
     * The mail lets them either self-confirm they paid via the previous
     * SAPRF site (signed URL — see the two `confirm-old-site-payment`
     * routes below) or click through to the normal payment flow.
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
     * Landing page reached when the shooter clicks the signed "I paid on
     * the old site" link in the inquiry email. Deliberately a two-step
     * flow — a bare GET must not mutate anything so that link previews,
     * inbox pre-fetchers, or a forwarded email can't self-confirm the
     * payment without a human clicking through.
     *
     * The signed-URL middleware guards both the GET and the POST, so
     * the identity check is the same in both places even though no
     * session is required.
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
