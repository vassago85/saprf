<?php

namespace App\Http\Controllers;

use App\Models\MatchRegistration;
use App\Models\Membership;
use App\Models\MembershipPayment;
use App\Models\Payment;
use App\Services\AuditLogService;
use App\Services\PayFastService;
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

        return view('payments.success', compact('payment'));
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
        $data = $request->all();

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

    public function payMembership(Request $request, Membership $membership): RedirectResponse
    {
        $user = $request->user();

        if ($membership->user_id !== $user->id && ! $user->hasRole(['owner', 'admin'])) {
            abort(403);
        }

        if ($membership->payment_status === 'paid') {
            return redirect()->route('memberships.show', $membership)
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
        }

        if ($payable instanceof Membership) {
            $payable->update([
                'payment_status' => 'paid',
                'status' => 'active',
            ]);

            MembershipPayment::create([
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
        }
    }
}
