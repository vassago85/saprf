<?php

namespace App\Http\Controllers;

use App\Models\CreditRefundRequest;
use App\Services\CreditRefundService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
            ...CreditRefundService::bankDetailRules(),
        ]);

        $this->refunds->request(
            $request->user(),
            (float) $validated['amount'],
            CreditRefundService::normaliseBankDetails($validated),
        );

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
            ->with(['user', 'registration.match'])
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
