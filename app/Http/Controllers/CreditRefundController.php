<?php

namespace App\Http\Controllers;

use App\Models\CreditRefundRequest;
use App\Services\CreditRefundService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class CreditRefundController extends Controller
{
    public function __construct(
        private readonly CreditRefundService $refunds,
    ) {}

    public function create(Request $request): View
    {
        $user = $request->user();
        $summary = $user->accountCreditSummary();
        $pending = $this->refunds->pendingFor($user);
        $latest = CreditRefundRequest::query()
            ->where('user_id', $user->id)
            ->latest('id')
            ->first();

        return view('account.refund', [
            'enabled' => $this->refunds->enabled(),
            'summary' => $summary,
            'pending' => $pending,
            'latest' => $latest,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01', 'max:999999.99'],
            'account_holder' => ['required', 'string', 'max:255'],
            'bank_name' => ['required', 'string', 'max:100'],
            'account_number' => ['required', 'string', 'max:30'],
            'branch_code' => ['required', 'string', 'max:12'],
            'member_note' => ['nullable', 'string', 'max:500'],
        ]);

        $accountNumber = preg_replace('/\s+/', '', $validated['account_number']) ?? '';
        $branchCode = preg_replace('/\s+/', '', $validated['branch_code']) ?? '';

        if (! preg_match('/^\d{6,16}$/', $accountNumber)) {
            throw ValidationException::withMessages([
                'account_number' => 'Enter the account number as digits only.',
            ]);
        }

        if (! preg_match('/^\d{4,10}$/', $branchCode)) {
            throw ValidationException::withMessages([
                'branch_code' => 'Enter the branch code as digits.',
            ]);
        }

        $this->refunds->request($request->user(), (float) $validated['amount'], [
            'account_holder' => $validated['account_holder'],
            'bank_name' => $validated['bank_name'],
            'account_number' => $accountNumber,
            'branch_code' => $branchCode,
            'member_note' => $validated['member_note'] ?? null,
        ]);

        return redirect()->route('account.refund')
            ->with('success', 'Refund request sent. That amount is held until it is paid.');
    }

    public function index(Request $request): View
    {
        $status = $request->string('status')->toString();
        if (! in_array($status, ['pending', 'paid', 'declined', 'all'], true)) {
            $status = 'pending';
        }

        $refunds = CreditRefundRequest::query()
            ->with('user')
            ->when($status !== 'all', fn ($query) => $query->where('status', $status))
            ->latest('id')
            ->paginate(30)
            ->withQueryString();

        return view('financials.credit-refunds', [
            'refunds' => $refunds,
            'status' => $status,
            'enabled' => $this->refunds->enabled(),
            'pendingCount' => CreditRefundRequest::query()->where('status', CreditRefundRequest::STATUS_PENDING)->count(),
        ]);
    }

    public function pay(Request $request, CreditRefundRequest $creditRefundRequest): RedirectResponse
    {
        $validated = $request->validate([
            'payment_reference' => ['required', 'string', 'max:100'],
        ]);

        $this->refunds->markPaid($creditRefundRequest, $request->user(), $validated['payment_reference']);

        return redirect()->route('financials.credit-refunds.index')
            ->with('success', 'Refund marked paid. The credit has been removed from their account.');
    }

    public function decline(Request $request, CreditRefundRequest $creditRefundRequest): RedirectResponse
    {
        $validated = $request->validate([
            'decline_reason' => ['required', 'string', 'max:500'],
        ]);

        $this->refunds->decline($creditRefundRequest, $request->user(), $validated['decline_reason']);

        return redirect()->route('financials.credit-refunds.index')
            ->with('success', 'Refund declined. The credit is available on their account again.');
    }
}
