<?php

namespace App\Http\Controllers;

use App\Models\FinancialTransaction;
use App\Models\MatchRegistration;
use App\Models\Membership;
use App\Models\MembershipFeeTier;
use App\Models\MembershipPayment;
use App\Models\Payment;
use App\Models\User;
use App\Notifications\MembershipConfirmedNotification;
use App\Notifications\PaymentReceivedNotification;
use App\Services\AuditLogService;
use App\Services\FinancialService;
use App\Services\PayFastService;
use App\Services\SettingsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class PaymentController extends Controller
{
    public function __construct(
        private readonly PayFastService $payFastService,
        private readonly AuditLogService $auditLogService,
    ) {}

    /**
     * Roles allowed to act on a payment that isn't theirs (support / reconciliation).
     */
    private const PAYMENT_STAFF_ROLES = ['developer', 'exco', 'owner', 'admin'];

    public function redirect(Request $request, Payment $payment): View|RedirectResponse
    {
        $this->authorizePayment($request, $payment);

        if (! $payment->isPending()) {
            return redirect()->route('registrations.index')
                ->with('info', 'This payment has already been processed.');
        }

        if (! $this->payFastService->isEnabled()) {
            return redirect()->back()
                ->with('error', 'Online payments are not currently available. Please contact the administrator.');
        }

        $user = $payment->user;
        $formData = $this->payFastService->buildPaymentData($payment, $user);
        $actionUrl = $this->payFastService->getFormActionUrl();

        return view('payments.redirect', compact('payment', 'formData', 'actionUrl'));
    }

    public function returnFromGateway(Request $request): View
    {
        // m_payment_id arrives in the query string, so it is only a hint — resolve
        // it against the signed-in user before putting anything in the view.
        $payment = $this->ownedPaymentFromQuery($request);

        // Return URL is not authoritative (ITN is), but poll the success page so
        // the UI flips to Paid as soon as the webhook lands.
        return view('payments.success', compact('payment'));
    }

    public function status(Request $request, Payment $payment): \Illuminate\Http\JsonResponse
    {
        $this->authorizePayment($request, $payment);

        $payment->refresh();

        return response()->json([
            'm_payment_id' => $payment->m_payment_id,
            'status' => $payment->status,
            'completed' => $payment->isCompleted(),
            'paid_at' => $payment->paid_at?->toIso8601String(),
        ]);
    }

    public function cancel(Request $request): View
    {
        $payment = $this->ownedPaymentFromQuery($request);

        if ($payment && $payment->isPending()) {
            $payment->update(['status' => 'cancelled']);
        }

        return view('payments.cancelled', compact('payment'));
    }

    /**
     * Resolve ?m_payment_id= to a payment the current user is allowed to see.
     * Returns null rather than aborting: these are gateway landing pages, so a
     * stale or foreign reference should render an empty state, not a 403.
     */
    private function ownedPaymentFromQuery(Request $request): ?Payment
    {
        $mPaymentId = $request->query('m_payment_id');

        if (! is_string($mPaymentId) || $mPaymentId === '') {
            return null;
        }

        $payment = Payment::where('m_payment_id', $mPaymentId)->first();

        return $payment && $this->userMayActOn($request->user(), $payment) ? $payment : null;
    }

    /**
     * PayFast posts the payer's name, email and signature with every ITN. We
     * still want the audit trail, so keep the reference/amount/status fields
     * and mask the rest rather than dropping the log line entirely.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function redactItn(array $data): array
    {
        $sensitive = ['name_first', 'name_last', 'email_address', 'cell_number', 'signature', 'token'];

        foreach ($sensitive as $key) {
            if (array_key_exists($key, $data) && $data[$key] !== null && $data[$key] !== '') {
                $data[$key] = '[redacted]';
            }
        }

        return $data;
    }

    private function authorizePayment(Request $request, Payment $payment): void
    {
        abort_unless($this->userMayActOn($request->user(), $payment), 403);
    }

    private function userMayActOn(?User $user, Payment $payment): bool
    {
        if (! $user) {
            return false;
        }

        return $payment->user_id === $user->id
            || $user->findManagedAccount($payment->user_id) !== null
            || $user->hasAnyRole(self::PAYMENT_STAFF_ROLES);
    }

    /**
     * The signed-in member, or one of their managed family accounts when
     * `for_user` is present. Foreign accounts are rejected.
     */
    private function resolveManagedSubject(User $actor, mixed $forUserId): User
    {
        if ($forUserId === null || $forUserId === '') {
            return $actor;
        }

        $subject = $actor->findManagedAccount($forUserId);
        abort_unless($subject, 403, 'You can only manage your own family accounts.');

        return $subject;
    }

    /**
     * @return array<string, int>
     */
    private function membershipPageParams(User $subject, User $actor): array
    {
        return $subject->id === $actor->id ? [] : ['for_user' => $subject->id];
    }

    public function notify(Request $request): \Illuminate\Http\Response
    {
        // Prefer raw POST body fields only (query string would corrupt the signature).
        $data = $request->post();
        if ($data === []) {
            $data = $request->request->all();
        }

        Log::info('PayFast ITN received', ['data' => $this->redactItn($data), 'ip' => $request->ip()]);

        $errors = $this->payFastService->validateItnRequest($data, $request->ip());
        $sandboxOverride = false;

        if (! empty($errors)) {
            // Sandbox rescue: if signature fails but the ITN clearly matches a
            // pending payment (ref + COMPLETE + amount), accept it so registrations
            // still update. Live mode never uses this path.
            if ($this->payFastService->isSandbox() && $this->sandboxItnMatchesPendingPayment($data)) {
                $sandboxOverride = true;
                Log::warning('PayFast ITN signature failed; accepting via sandbox amount match', [
                    'errors' => $errors,
                    'm_payment_id' => $data['m_payment_id'] ?? null,
                ]);
            } else {
                Log::warning('PayFast ITN validation failed', ['errors' => $errors, 'data' => $this->redactItn($data)]);

                return response('INVALID', 400)
                    ->header('Content-Type', 'text/plain');
            }
        }

        $payment = Payment::where('m_payment_id', $data['m_payment_id'] ?? '')->first();

        if (! $payment) {
            Log::warning('PayFast ITN: payment not found', ['m_payment_id' => $data['m_payment_id'] ?? '']);

            return response('NOT FOUND', 404)->header('Content-Type', 'text/plain');
        }

        if ($payment->isCompleted()) {
            return response('OK', 200)->header('Content-Type', 'text/plain');
        }

        $pfPaymentStatus = $data['payment_status'] ?? '';
        $settlement = Payment::settlementFromItn($data);
        $payment->update([
            'gateway_payment_id' => $data['pf_payment_id'] ?? null,
            'gateway_response' => array_merge($data, $sandboxOverride ? ['sandbox_override' => true] : []),
            'status' => $pfPaymentStatus === 'COMPLETE' ? 'completed' : 'failed',
            'paid_at' => $pfPaymentStatus === 'COMPLETE' ? now() : null,
            'amount_gross' => $settlement['gross'],
            'amount_fee' => $settlement['fee'],
            'amount_net' => $settlement['net'],
        ]);

        if ($pfPaymentStatus === 'COMPLETE') {
            $this->handleSuccessfulPayment($payment);
        }

        Log::info('PayFast ITN processed', [
            'm_payment_id' => $payment->m_payment_id,
            'status' => $payment->status,
            'sandbox_override' => $sandboxOverride,
        ]);

        return response('OK', 200)->header('Content-Type', 'text/plain');
    }

    /**
     * Sandbox-only safety net when PayFast's signature string doesn't match ours
     * but the notification is clearly for our pending payment.
     */
    private function sandboxItnMatchesPendingPayment(array $data): bool
    {
        if (($data['payment_status'] ?? '') !== 'COMPLETE') {
            return false;
        }

        $payment = Payment::where('m_payment_id', $data['m_payment_id'] ?? '')->first();
        if (! $payment || ! $payment->isPending()) {
            return false;
        }

        if (! isset($data['amount_gross'])) {
            return false;
        }

        return abs((float) $data['amount_gross'] - (float) $payment->amount) < 0.011;
    }

    public function joinMembership(Request $request): RedirectResponse
    {
        $payer = $request->user();
        $user = $this->resolveManagedSubject($payer, $request->input('for_user'));

        $existing = Membership::where('user_id', $user->id)->latest()->first();

        if ($existing && $existing->status === 'active' && $existing->payment_status === 'paid') {
            return redirect()->route('my-membership', $this->membershipPageParams($user, $payer))
                ->with('info', 'You already have an active membership.');
        }

        if (! $this->payFastService->isEnabled()) {
            return redirect()->route('dashboard')
                ->with('error', 'Online payments are not currently available. Please contact the administrator.');
        }

        $validated = $request->validate([
            'fee_tier_id' => [
                'nullable',
                Rule::exists('membership_fee_tiers', 'id')->where('is_active', true),
            ],
        ]);

        $tier = isset($validated['fee_tier_id'])
            ? MembershipFeeTier::find($validated['fee_tier_id'])
            : MembershipFeeTier::defaultTier();

        $fee = $this->resolveFee($tier);
        $expiry = now()->addMonths($tier?->duration_months ?? 12)->toDateString();

        if ($existing) {
            $existing->update([
                'status' => 'pending',
                'payment_status' => 'unpaid',
                'fee_tier_id' => $tier?->id,
                'start_date' => now()->toDateString(),
                'expiry_date' => $expiry,
            ]);
            $membership = $existing;
        } else {
            $membership = Membership::create([
                'user_id' => $user->id,
                'saprf_number' => Membership::nextSaprfNumber(),
                'membership_type' => 'paid',
                'fee_tier_id' => $tier?->id,
                'status' => 'pending',
                'payment_status' => 'unpaid',
                'start_date' => now()->toDateString(),
                'expiry_date' => $expiry,
            ]);
        }

        $payment = Payment::create([
            'payable_type' => Membership::class,
            'payable_id' => $membership->id,
            'user_id' => $payer->id,
            'amount' => $fee,
            'm_payment_id' => Payment::generateReference('MEM'),
        ]);

        $this->auditLogService->log(
            $payer,
            'membership.self_service_join',
            'Membership',
            $membership->id,
            null,
            ['membership_id' => $membership->id, 'amount' => $fee, 'fee_tier_id' => $tier?->id, 'fee_tier' => $tier?->name, 'for_user_id' => $user->id],
        );

        return redirect()->route('payments.redirect', $payment);
    }

    public function payMembership(Request $request, Membership $membership): RedirectResponse
    {
        $user = $request->user();

        $mayPay = $membership->user_id === $user->id
            || $user->findManagedAccount($membership->user_id) !== null
            || $user->hasRole(['owner', 'admin']);

        if (! $mayPay) {
            abort(403);
        }

        if ($membership->payment_status === 'paid') {
            $subject = $membership->user ?? $user;

            return redirect()->route('my-membership', $this->membershipPageParams($subject, $user))
                ->with('info', 'This membership is already paid.');
        }

        if (! $this->payFastService->isEnabled()) {
            return redirect()->back()
                ->with('error', 'Online payments are not currently available.');
        }

        $fee = $this->resolveFee($membership->feeTier ?? MembershipFeeTier::defaultTier());

        $payment = Payment::create([
            'payable_type' => Membership::class,
            'payable_id' => $membership->id,
            'user_id' => $user->id,
            'amount' => $fee,
            'm_payment_id' => Payment::generateReference('MEM'),
        ]);

        return redirect()->route('payments.redirect', $payment);
    }

    public function payRegistration(Request $request, MatchRegistration $registration): RedirectResponse
    {
        $user = $request->user();

        if ($registration->user_id !== $user->id && ! $user->hasAnyRole(['owner', 'admin'])) {
            abort(403);
        }

        if ($registration->registration_status === 'cancelled') {
            return redirect()->route('registrations.show', $registration)
                ->with('error', 'This registration has been cancelled and can no longer be paid.');
        }

        if ($registration->payment_status === 'paid') {
            return redirect()->route('registrations.show', $registration)
                ->with('info', 'This registration is already paid.');
        }

        $fee = (float) $registration->fee_amount;

        if ($fee <= 0) {
            return redirect()->route('registrations.show', $registration)
                ->with('info', 'This registration has no fee to pay.');
        }

        if (! $this->payFastService->isEnabled()) {
            return redirect()->back()
                ->with('error', 'Online payments are not currently available.');
        }

        // Reuse an existing pending payment (initial attempt still open in
        // another tab) — PayFast's m_payment_id is single-use, so we don't
        // touch cancelled/failed rows and instead create a fresh one.
        $payment = Payment::where('payable_type', MatchRegistration::class)
            ->where('payable_id', $registration->id)
            ->where('status', 'pending')
            ->latest('id')
            ->first();

        if (! $payment) {
            $payment = Payment::create([
                'payable_type' => MatchRegistration::class,
                'payable_id' => $registration->id,
                'user_id' => $user->id,
                'amount' => $fee,
                'm_payment_id' => Payment::generateReference('REG'),
            ]);
        }

        return redirect()->route('payments.redirect', $payment);
    }

    /**
     * Price for a membership fee tier, falling back to the legacy global
     * annual fee setting when no tier is configured (fresh installs, etc.).
     */
    private function resolveFee(?MembershipFeeTier $tier): float
    {
        if ($tier) {
            return (float) $tier->price;
        }

        return (float) app(SettingsService::class)->get('annual_membership_fee', 500);
    }

    private function handleSuccessfulPayment(Payment $payment): void
    {
        $payable = $payment->payable;

        if ($payable instanceof MatchRegistration) {
            $payable->update([
                'payment_status' => 'paid',
                'registration_status' => $payable->registration_status === 'pending' ? 'confirmed' : $payable->registration_status,
            ]);

            if ($payment->amount_fee !== null) {
                $payable->applyActualGatewayFee((float) $payment->amount_fee);
            }

            $this->auditLogService->log(
                $payment->user,
                'registration.payment.completed',
                'MatchRegistration',
                $payable->id,
                ['payment_status' => 'unpaid'],
                [
                    'payment_status' => 'paid',
                    'payment_id' => $payment->id,
                    'gateway_fee' => $payment->amount_fee,
                    'amount_net' => $payment->amount_net,
                ],
            );

            FinancialTransaction::create([
                'type' => 'payment',
                'source_type' => 'match_registration',
                'source_id' => $payable->id,
                'user_id' => $payment->user_id,
                'amount' => $payment->amount,
                'description' => 'Match registration payment via PayFast',
                'meta' => [
                    'payment_id' => $payment->id,
                    'm_payment_id' => $payment->m_payment_id,
                    'gateway_payment_id' => $payment->gateway_payment_id,
                    'match_id' => $payable->match_id,
                    'amount_gross' => $payment->amount_gross,
                    'amount_fee' => $payment->amount_fee,
                    'amount_net' => $payment->amount_net,
                ],
            ]);

            if ($payment->user) {
                try {
                    $payment->user->notify(new PaymentReceivedNotification($payment, 'registration'));
                } catch (\Throwable $e) {
                    Log::warning('Failed to send registration payment notification', ['error' => $e->getMessage()]);
                }
            }
        }

        if ($payable instanceof Membership) {
            $payable->update([
                'payment_status' => 'paid',
                'status' => 'active',
            ]);

            $membershipPayment = MembershipPayment::create([
                'membership_id' => $payable->id,
                'amount' => $payment->amount,
                'gateway_fee' => $payment->amount_fee,
                'payment_date' => now()->toDateString(),
                'payment_reference' => $payment->m_payment_id,
                'payment_method' => 'payfast',
                'status' => 'confirmed',
                'notes' => 'Online payment via PayFast (PF ID: ' . $payment->gateway_payment_id . ')',
            ]);

            $this->auditLogService->log(
                $payment->user,
                'membership.payment.completed',
                'Membership',
                $payable->id,
                ['payment_status' => $payable->getOriginal('payment_status')],
                ['payment_status' => 'paid', 'payment_id' => $payment->id],
            );

            FinancialTransaction::create([
                'type' => 'payment',
                'source_type' => 'membership',
                'source_id' => $payable->id,
                'user_id' => $payment->user_id,
                'amount' => $payment->amount,
                'description' => 'Membership payment via PayFast',
                'meta' => [
                    'payment_id' => $payment->id,
                    'm_payment_id' => $payment->m_payment_id,
                    'gateway_payment_id' => $payment->gateway_payment_id,
                    'saprf_number' => $payable->saprf_number,
                    'amount_gross' => $payment->amount_gross,
                    'amount_fee' => $payment->amount_fee,
                    'amount_net' => $payment->amount_net,
                ],
            ]);

            if ($payment->user) {
                try {
                    $payment->user->notify(new MembershipConfirmedNotification($payable, $membershipPayment));
                    $payment->user->notify(new PaymentReceivedNotification($payment, 'membership'));
                } catch (\Throwable $e) {
                    Log::warning('Failed to send membership confirmation notification', ['error' => $e->getMessage()]);
                }
            }
        }

        app(FinancialService::class)->clearCache();
    }
}
