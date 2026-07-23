<?php

namespace App\Http\Controllers;

use App\Models\FinancialTransaction;
use App\Models\MatchRegistration;
use App\Models\Membership;
use App\Models\MembershipPayment;
use App\Models\Payment;
use App\Notifications\MembershipConfirmedNotification;
use App\Notifications\PaymentReceivedNotification;
use App\Services\AuditLogService;
use App\Services\PayFastService;
use App\Services\SettingsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

class PaymentController extends Controller
{
    public function __construct(
        private readonly PayFastService $payFastService,
        private readonly AuditLogService $auditLogService,
    ) {}

    public function redirect(Payment $payment): View|RedirectResponse
    {
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
        $mPaymentId = $request->query('m_payment_id');
        $payment = $mPaymentId ? Payment::where('m_payment_id', $mPaymentId)->first() : null;

        // Return URL is not authoritative (ITN is), but poll the success page so
        // the UI flips to Paid as soon as the webhook lands.
        return view('payments.success', compact('payment'));
    }

    public function status(Payment $payment): \Illuminate\Http\JsonResponse
    {
        $user = request()->user();
        if (! $user || ($payment->user_id !== $user->id && ! $user->hasAnyRole(['developer', 'exco', 'owner', 'admin']))) {
            abort(403);
        }

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
        $mPaymentId = $request->query('m_payment_id');
        $payment = $mPaymentId ? Payment::where('m_payment_id', $mPaymentId)->first() : null;

        if ($payment && $payment->isPending()) {
            $payment->update(['status' => 'cancelled']);
        }

        return view('payments.cancelled', compact('payment'));
    }

    public function notify(Request $request): \Illuminate\Http\Response
    {
        // Only POST body — query-string keys would corrupt the ITN signature string.
        $data = $request->post();

        Log::info('PayFast ITN received', ['data' => $data, 'ip' => $request->ip()]);

        $errors = $this->payFastService->validateItnRequest($data, $request->ip());

        if (! empty($errors)) {
            Log::warning('PayFast ITN validation failed', ['errors' => $errors, 'data' => $data]);

            return response('INVALID', 400);
        }

        $payment = Payment::where('m_payment_id', $data['m_payment_id'] ?? '')->first();

        if (! $payment) {
            Log::warning('PayFast ITN: payment not found', ['m_payment_id' => $data['m_payment_id'] ?? '']);

            return response('NOT FOUND', 404);
        }

        if ($payment->isCompleted()) {
            return response('OK', 200);
        }

        $pfPaymentStatus = $data['payment_status'] ?? '';
        $payment->update([
            'gateway_payment_id' => $data['pf_payment_id'] ?? null,
            'gateway_response' => $data,
            'status' => $pfPaymentStatus === 'COMPLETE' ? 'completed' : 'failed',
            'paid_at' => $pfPaymentStatus === 'COMPLETE' ? now() : null,
        ]);

        if ($pfPaymentStatus === 'COMPLETE') {
            $this->handleSuccessfulPayment($payment);
        }

        Log::info('PayFast ITN processed', [
            'm_payment_id' => $payment->m_payment_id,
            'status' => $payment->status,
        ]);

        return response('OK', 200);
    }

    public function joinMembership(Request $request): RedirectResponse
    {
        $user = $request->user();

        $existing = Membership::where('user_id', $user->id)->latest()->first();

        if ($existing && $existing->status === 'active' && $existing->payment_status === 'paid') {
            return redirect()->route('my-membership')
                ->with('info', 'You already have an active membership.');
        }

        if (! $this->payFastService->isEnabled()) {
            return redirect()->route('dashboard')
                ->with('error', 'Online payments are not currently available. Please contact the administrator.');
        }

        $fee = (float) app(SettingsService::class)->get('annual_membership_fee', 500);

        if ($existing) {
            $existing->update([
                'status' => 'pending',
                'payment_status' => 'unpaid',
                'start_date' => now()->toDateString(),
                'expiry_date' => now()->addYear()->toDateString(),
            ]);
            $membership = $existing;
        } else {
            $membership = Membership::create([
                'user_id' => $user->id,
                'saprf_number' => $this->generateSaprfNumber(),
                'membership_type' => 'paid',
                'status' => 'pending',
                'payment_status' => 'unpaid',
                'start_date' => now()->toDateString(),
                'expiry_date' => now()->addYear()->toDateString(),
            ]);
        }

        $payment = Payment::create([
            'payable_type' => Membership::class,
            'payable_id' => $membership->id,
            'user_id' => $user->id,
            'amount' => $fee,
            'm_payment_id' => Payment::generateReference('MEM'),
        ]);

        $this->auditLogService->log(
            $user,
            'membership.self_service_join',
            'Membership',
            $membership->id,
            null,
            ['membership_id' => $membership->id, 'amount' => $fee],
        );

        return redirect()->route('payments.redirect', $payment);
    }

    private function generateSaprfNumber(): string
    {
        $year = now()->year;
        $prefix = "SAPRF-{$year}-";

        $maxNum = Membership::where('saprf_number', 'like', "{$prefix}%")
            ->pluck('saprf_number')
            ->map(fn (string $num) => (int) str_replace($prefix, '', $num))
            ->max();

        $next = ($maxNum ?? 0) + 1;

        return $prefix . str_pad($next, 4, '0', STR_PAD_LEFT);
    }

    public function payMembership(Request $request, Membership $membership): RedirectResponse
    {
        $user = $request->user();

        if ($membership->user_id !== $user->id && ! $user->hasRole(['owner', 'admin'])) {
            abort(403);
        }

        if ($membership->payment_status === 'paid') {
            return redirect()->route('my-membership')
                ->with('info', 'This membership is already paid.');
        }

        if (! $this->payFastService->isEnabled()) {
            return redirect()->back()
                ->with('error', 'Online payments are not currently available.');
        }

        $fee = (float) app(\App\Services\SettingsService::class)->get('annual_membership_fee', 500);

        $payment = Payment::create([
            'payable_type' => Membership::class,
            'payable_id' => $membership->id,
            'user_id' => $user->id,
            'amount' => $fee,
            'm_payment_id' => Payment::generateReference('MEM'),
        ]);

        return redirect()->route('payments.redirect', $payment);
    }

    private function handleSuccessfulPayment(Payment $payment): void
    {
        $payable = $payment->payable;

        if ($payable instanceof MatchRegistration) {
            $payable->update([
                'payment_status' => 'paid',
                'registration_status' => $payable->registration_status === 'pending' ? 'confirmed' : $payable->registration_status,
            ]);

            $this->auditLogService->log(
                $payment->user,
                'registration.payment.completed',
                'MatchRegistration',
                $payable->id,
                ['payment_status' => 'unpaid'],
                ['payment_status' => 'paid', 'payment_id' => $payment->id],
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
    }
}
