<?php

namespace App\Http\Controllers;

use App\Models\FinancialTransaction;
use App\Models\MatchEvent;
use App\Models\Payout;
use App\Models\PlatformExpense;
use App\Models\PlatformIncome;
use App\Models\Sponsor;
use App\Services\AuditLogService;
use App\Services\FinancialResetService;
use App\Services\FinancialService;
use App\Services\PlatformPayoutService;
use App\Services\SettingsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\View\View;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class FinancialController extends Controller
{
    public function __construct(
        private readonly FinancialService $financials,
        private readonly AuditLogService $audit,
        private readonly SettingsService $settingsService,
        private readonly FinancialResetService $reset,
        private readonly PlatformPayoutService $platformPayouts,
    ) {}

    // ── Platform Dashboard ──

    public function dashboard(Request $request): View
    {
        [$from, $to] = $this->parseDateRange($request);

        $summary = $this->financials->platformSummary($from, $to);
        $matchBreakdown = $this->financials->revenueByMatch($from, $to);
        $monthlyTrend = $this->financials->monthlyTrend(12);
        $seasons = range(now()->year, now()->year - 3);
        $settings = $this->settingsService->all();

        // Platform operator's own dashboard block: what have they earned this
        // month (not yet billable — current month), what's outstanding for
        // completed months, and are they even wired up?
        $mtdPreview = $this->platformPayouts->preview(now());
        $unsettledMonths = $this->platformPayouts->unsettledMonths();
        $platformOperatorConfigured = $mtdPreview['operator_user_id'] !== null;

        return view('financials.dashboard', compact(
            'summary', 'matchBreakdown', 'monthlyTrend', 'seasons', 'from', 'to', 'settings',
            'mtdPreview', 'unsettledMonths', 'platformOperatorConfigured',
        ));
    }

    // ── Match Financial Report ──

    public function matchReport(Request $request, MatchEvent $match): View
    {
        $user = $request->user();

        // The report is reachable by either finance staff or the MD who created
        // this match. Anyone else — including MDs looking at someone else's
        // match — is bounced.
        abort_unless(
            $user && (
                $user->hasAnyRole(['developer', 'exco', 'owner', 'admin'])
                || $user->id === $match->created_by
            ),
            403,
        );

        $match->load(['registrations', 'expenses', 'user']);
        $financials = $this->financials->matchFinancials($match);

        // Expenses + P&L are the MD's private ledger. Owner, admin, exco, and
        // developer only see the amount the federation owes the MD (md_net).
        // Everything below the payout line — expenses table, profit/loss,
        // "match expenses" row — is fenced off unless the viewer created the
        // match.
        $viewerIsMatchDirector = $user->id === $match->created_by;

        return view('financials.match-report', compact(
            'match', 'financials', 'viewerIsMatchDirector',
        ));
    }

    // ── Clear Finance Data (developer only) ──

    public function confirmReset(): View
    {
        return view('financials.reset', [
            'preview' => $this->reset->preview(),
        ]);
    }

    public function reset(Request $request): RedirectResponse
    {
        $request->validate([
            'confirmation' => ['required', 'in:CLEAR FINANCE'],
        ], [
            'confirmation.in' => 'Type CLEAR FINANCE exactly to confirm.',
        ]);

        $result = $this->reset->wipe();

        $this->audit->log(
            $request->user(),
            'financial_data_cleared',
            'financials',
            null,
            $result['before'] + ['paid_registrations' => $result['paid_registrations']],
            ['registrations_reset' => $result['registrations_reset']],
        );

        return redirect()->route('financials.dashboard')
            ->with('success', "Finance data cleared. Reset {$result['registrations_reset']} registration(s) and emptied all ledgers — the platform is starting fresh.");
    }

    // ── Payouts ──

    public function payouts(Request $request): View
    {
        $status = $request->input('status');
        $type = $request->input('type');

        $payouts = Payout::query()
            ->with(['payeeUser', 'match', 'creator'])
            ->when($status, fn ($q) => $q->where('status', $status))
            ->when($type, fn ($q) => $q->where('payee_type', $type))
            ->orderByDesc('created_at')
            ->paginate(25)
            ->withQueryString();

        $pendingTotal = Payout::where('status', 'pending')->sum('net_amount');
        $paidTotal = Payout::where('status', 'paid')->sum('paid_amount');

        // Dedicated "MD payout requests" surface — this is what the sidebar
        // badge and admin dashboard card point at. Kept separate from the
        // generic pending total so the admin can zero in on what MDs are
        // waiting on without wading through platform-operator payouts.
        $pendingMdPayouts = Payout::with(['payeeUser', 'match', 'creator'])
            ->where('payee_type', 'match_director')
            ->where('status', 'pending')
            ->orderBy('created_at')
            ->get();

        // Unsettled months surface a hint above the listing so the operator
        // knows there's money outstanding without having to go digging.
        $unsettledMonths = $this->platformPayouts->unsettledMonths();

        return view('financials.payouts', compact(
            'payouts', 'pendingTotal', 'paidTotal', 'pendingMdPayouts',
            'status', 'type', 'unsettledMonths',
        ));
    }

    public function createPayout(Request $request): View
    {
        $matches = MatchEvent::where('status', 'completed')
            ->whereDoesntHave('payouts', fn ($q) => $q->where('payee_type', 'match_director'))
            ->with('user')
            ->orderByDesc('match_date')
            ->get();

        return view('financials.create-payout', compact('matches'));
    }

    public function storePayout(Request $request)
    {
        $validated = $request->validate([
            'match_id' => ['required', 'exists:matches,id'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $match = MatchEvent::findOrFail($validated['match_id']);

        $payout = $this->financials->createMdPayout(
            $match,
            $request->user(),
            $validated['notes'] ?? null,
        );

        $this->audit->log(
            $request->user(),
            'payout_created',
            'payout',
            $payout->id,
            null,
            $payout->toArray(),
        );

        return redirect()->route('financials.payouts')
            ->with('success', "Payout {$payout->reference} created for R" . number_format($payout->net_amount, 2));
    }

    // ── Platform Operator Payouts ──

    public function createPlatformPayout(Request $request): View
    {
        $monthInput = $request->input('month');
        $month = $this->parseMonth($monthInput) ?? now()->subMonthNoOverflow()->startOfMonth();

        $preview = $this->platformPayouts->preview($month);
        $unsettledMonths = $this->platformPayouts->unsettledMonths();
        $operatorConfigured = $preview['operator_user_id'] !== null;

        return view('financials.create-platform-payout', compact(
            'preview', 'month', 'unsettledMonths', 'operatorConfigured',
        ));
    }

    public function storePlatformPayout(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'month' => ['required', 'regex:/^\d{4}-\d{2}$/'],
            'notes' => ['nullable', 'string', 'max:500'],
        ], [
            'month.regex' => 'Pick a valid month (YYYY-MM).',
        ]);

        $month = $this->parseMonth($validated['month']);
        if (! $month) {
            return back()->withErrors(['month' => 'Pick a valid month.'])->withInput();
        }

        try {
            $payout = $this->platformPayouts->createForMonth($month, $request->user(), $validated['notes'] ?? null);
        } catch (RuntimeException $e) {
            return back()->withErrors(['month' => $e->getMessage()])->withInput();
        }

        FinancialTransaction::create([
            'type' => 'payout',
            'source_type' => 'payout',
            'source_id' => $payout->id,
            'user_id' => $request->user()->id,
            'amount' => $payout->net_amount,
            'description' => "Platform payout {$payout->reference} created for {$month->format('F Y')}",
        ]);

        $this->audit->log(
            $request->user(),
            'platform_payout_created',
            'payout',
            $payout->id,
            null,
            $payout->toArray(),
        );

        return redirect()->route('financials.payouts')
            ->with('success', "Platform payout {$payout->reference} created for {$month->format('F Y')} (R" . number_format($payout->net_amount, 2) . ').');
    }

    private function parseMonth(?string $value): ?Carbon
    {
        if (! $value || ! preg_match('/^\d{4}-\d{2}$/', $value)) {
            return null;
        }

        try {
            return Carbon::createFromFormat('Y-m-d', $value . '-01')->startOfMonth();
        } catch (\Throwable) {
            return null;
        }
    }

    public function markPaid(Request $request, Payout $payout)
    {
        $validated = $request->validate([
            'paid_amount' => ['required', 'numeric', 'min:0.01'],
            'payment_reference' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $oldValues = $payout->only(['status', 'paid_amount', 'paid_at']);

        $newPaid = (float) $payout->paid_amount + (float) $validated['paid_amount'];
        $status = $newPaid >= (float) $payout->net_amount ? 'paid' : 'partial';

        $payout->update([
            'paid_amount' => $newPaid,
            'paid_at' => now(),
            'payment_reference' => $validated['payment_reference'] ?? $payout->payment_reference,
            'notes' => $validated['notes'] ?? $payout->notes,
            'status' => $status,
        ]);

        FinancialTransaction::create([
            'type' => 'payout',
            'source_type' => 'payout',
            'source_id' => $payout->id,
            'user_id' => $request->user()->id,
            'amount' => $validated['paid_amount'],
            'description' => "Payment of R" . number_format($validated['paid_amount'], 2) . " on {$payout->reference}",
            'meta' => ['payment_reference' => $validated['payment_reference'] ?? null],
        ]);

        $this->audit->log(
            $request->user(),
            'payout_marked_paid',
            'payout',
            $payout->id,
            $oldValues,
            $payout->fresh()->toArray(),
        );

        return redirect()->route('financials.payouts')
            ->with('success', "Payment recorded on {$payout->reference}");
    }

    // ── Platform Expenses ──

    public function expenses(Request $request): View
    {
        $category = $request->input('category');

        $expenses = PlatformExpense::query()
            ->with('creator')
            ->when($category, fn ($q) => $q->where('category', $category))
            ->orderByDesc('expense_date')
            ->paginate(25);

        $totalAll = PlatformExpense::sum('amount');
        $totalFiltered = $category
            ? PlatformExpense::where('category', $category)->sum('amount')
            : $totalAll;

        $categories = PlatformExpense::CATEGORIES;

        return view('financials.expenses', compact('expenses', 'totalAll', 'totalFiltered', 'category', 'categories'));
    }

    public function createExpense(): View
    {
        $categories = PlatformExpense::CATEGORIES;

        return view('financials.create-expense', compact('categories'));
    }

    public function storeExpense(Request $request)
    {
        $validated = $request->validate([
            'category' => ['required', 'string', 'in:' . implode(',', array_keys(PlatformExpense::CATEGORIES))],
            'description' => ['required', 'string', 'max:255'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'expense_date' => ['required', 'date'],
            'reference' => ['nullable', 'string', 'max:100'],
            'vendor' => ['nullable', 'string', 'max:150'],
            'notes' => ['nullable', 'string', 'max:500'],
            'is_recurring' => ['nullable', 'boolean'],
        ]);

        $validated['is_recurring'] = $request->boolean('is_recurring');
        $validated['created_by'] = $request->user()->id;

        $expense = PlatformExpense::create($validated);

        FinancialTransaction::create([
            'type' => 'expense',
            'source_type' => 'platform_expense',
            'source_id' => $expense->id,
            'user_id' => $request->user()->id,
            'amount' => -$expense->amount,
            'description' => "Platform expense: {$expense->description}",
        ]);

        $this->audit->log(
            $request->user(),
            'platform_expense_created',
            'platform_expense',
            $expense->id,
            null,
            $expense->toArray(),
        );

        return redirect()->route('financials.expenses')
            ->with('success', "Expense recorded: {$expense->description} — R" . number_format($expense->amount, 2));
    }

    public function editExpense(PlatformExpense $expense): View
    {
        $categories = PlatformExpense::CATEGORIES;

        return view('financials.edit-expense', compact('expense', 'categories'));
    }

    public function updateExpense(Request $request, PlatformExpense $expense)
    {
        $validated = $request->validate([
            'category' => ['required', 'string', 'in:' . implode(',', array_keys(PlatformExpense::CATEGORIES))],
            'description' => ['required', 'string', 'max:255'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'expense_date' => ['required', 'date'],
            'reference' => ['nullable', 'string', 'max:100'],
            'vendor' => ['nullable', 'string', 'max:150'],
            'notes' => ['nullable', 'string', 'max:500'],
            'is_recurring' => ['nullable', 'boolean'],
        ]);

        $oldValues = $expense->toArray();
        $validated['is_recurring'] = $request->boolean('is_recurring');
        $expense->update($validated);

        $this->audit->log(
            $request->user(),
            'platform_expense_updated',
            'platform_expense',
            $expense->id,
            $oldValues,
            $expense->fresh()->toArray(),
        );

        return redirect()->route('financials.expenses')
            ->with('success', "Expense updated: {$expense->description}");
    }

    public function destroyExpense(Request $request, PlatformExpense $expense)
    {
        $this->audit->log(
            $request->user(),
            'platform_expense_deleted',
            'platform_expense',
            $expense->id,
            $expense->toArray(),
            null,
        );

        $expense->delete();

        return redirect()->route('financials.expenses')
            ->with('success', 'Expense deleted.');
    }

    // ── Platform Income ──

    public function income(Request $request): View
    {
        $category = $request->input('category');

        $incomeItems = PlatformIncome::query()
            ->with(['creator', 'sponsor'])
            ->when($category, fn ($q) => $q->where('category', $category))
            ->orderByDesc('income_date')
            ->paginate(25);

        $totalAll = PlatformIncome::sum('amount');
        $totalFiltered = $category
            ? PlatformIncome::where('category', $category)->sum('amount')
            : $totalAll;

        $categories = PlatformIncome::CATEGORIES;

        return view('financials.income', compact('incomeItems', 'totalAll', 'totalFiltered', 'category', 'categories'));
    }

    public function createIncome(): View
    {
        $categories = PlatformIncome::CATEGORIES;
        $sponsors = Sponsor::orderBy('name')->get(['id', 'name']);

        return view('financials.create-income', compact('categories', 'sponsors'));
    }

    public function storeIncome(Request $request)
    {
        $validated = $request->validate([
            'category' => ['required', 'string', 'in:' . implode(',', array_keys(PlatformIncome::CATEGORIES))],
            'sponsor_id' => ['nullable', 'exists:sponsors,id'],
            'description' => ['required', 'string', 'max:255'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'income_date' => ['required', 'date'],
            'source' => ['nullable', 'string', 'max:150'],
            'reference' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:500'],
            'is_recurring' => ['nullable', 'boolean'],
        ]);

        if ($validated['category'] !== 'sponsorship') {
            $validated['sponsor_id'] = null;
        }

        $validated['is_recurring'] = $request->boolean('is_recurring');
        $validated['created_by'] = $request->user()->id;

        $income = PlatformIncome::create($validated);

        FinancialTransaction::create([
            'type' => 'payment',
            'source_type' => 'platform_income',
            'source_id' => $income->id,
            'user_id' => $request->user()->id,
            'amount' => $income->amount,
            'description' => "Income: {$income->description}",
        ]);

        $this->audit->log($request->user(), 'platform_income_created', 'platform_income', $income->id, null, $income->toArray());

        return redirect()->route('financials.income')
            ->with('success', "Income recorded: {$income->description} — R" . number_format($income->amount, 2));
    }

    public function editIncome(PlatformIncome $income): View
    {
        $categories = PlatformIncome::CATEGORIES;
        $sponsors = Sponsor::orderBy('name')->get(['id', 'name']);

        return view('financials.edit-income', compact('income', 'categories', 'sponsors'));
    }

    public function updateIncome(Request $request, PlatformIncome $income)
    {
        $validated = $request->validate([
            'category' => ['required', 'string', 'in:' . implode(',', array_keys(PlatformIncome::CATEGORIES))],
            'sponsor_id' => ['nullable', 'exists:sponsors,id'],
            'description' => ['required', 'string', 'max:255'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'income_date' => ['required', 'date'],
            'source' => ['nullable', 'string', 'max:150'],
            'reference' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:500'],
            'is_recurring' => ['nullable', 'boolean'],
        ]);

        if ($validated['category'] !== 'sponsorship') {
            $validated['sponsor_id'] = null;
        }

        $oldValues = $income->toArray();
        $validated['is_recurring'] = $request->boolean('is_recurring');
        $income->update($validated);

        $this->audit->log($request->user(), 'platform_income_updated', 'platform_income', $income->id, $oldValues, $income->fresh()->toArray());

        return redirect()->route('financials.income')
            ->with('success', "Income updated: {$income->description}");
    }

    public function destroyIncome(Request $request, PlatformIncome $income)
    {
        $this->audit->log($request->user(), 'platform_income_deleted', 'platform_income', $income->id, $income->toArray(), null);
        $income->delete();

        return redirect()->route('financials.income')
            ->with('success', 'Income entry deleted.');
    }

    // ── Transactions Log ──

    public function transactions(Request $request): View
    {
        $type = $request->input('type');

        $transactions = FinancialTransaction::query()
            ->with('user')
            ->when($type, fn ($q) => $q->where('type', $type))
            ->orderByDesc('created_at')
            ->paginate(50);

        return view('financials.transactions', compact('transactions', 'type'));
    }

    // ── Exports ──

    public function exportDashboardCsv(Request $request): StreamedResponse
    {
        [$from, $to] = $this->parseDateRange($request);
        $summary = $this->financials->platformSummary($from, $to);

        $filename = 'SAPRF_Financial_Summary_' . now()->format('Y-m-d') . '.csv';

        return response()->streamDownload(function () use ($summary) {
            $handle = fopen('php://output', 'w');

            fputcsv($handle, ['Metric', 'Amount (ZAR)']);
            fputcsv($handle, ['Gross Income', $summary['gross_income']]);
            fputcsv($handle, ['Match Revenue', $summary['match_revenue']['gross']]);
            fputcsv($handle, ['Membership Revenue', $summary['membership_revenue']['gross']]);
            fputcsv($handle, ['Platform Fees', $summary['total_platform_fees']]);
            fputcsv($handle, ['SAPRF Fees', $summary['total_saprf_fees']]);
            fputcsv($handle, ['Gateway Fees', $summary['total_gateway_fees']]);
            fputcsv($handle, ['Surcharges', $summary['total_surcharges']]);
            fputcsv($handle, ['Net Revenue (SAPRF)', $summary['net_revenue']]);
            fputcsv($handle, ['MD Payouts', $summary['total_md_payouts']]);

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }

    public function exportMatchesCsv(Request $request): StreamedResponse
    {
        [$from, $to] = $this->parseDateRange($request);
        $matches = $this->financials->revenueByMatch($from, $to);

        $filename = 'SAPRF_Match_Revenue_' . now()->format('Y-m-d') . '.csv';

        return response()->streamDownload(function () use ($matches) {
            $handle = fopen('php://output', 'w');

            fputcsv($handle, [
                'Match', 'Date', 'Type', 'Level', 'Status',
                'Entries', 'Gross', 'SAPRF Fees', 'Platform Fees', 'Gateway Fees', 'MD Net',
            ]);

            foreach ($matches as $m) {
                fputcsv($handle, [
                    $m->name, $m->match_date, $m->match_type, $m->series_level, $m->match_status,
                    $m->entries, $m->gross, $m->saprf_fees, $m->platform_fees, $m->gateway_fees, $m->md_net,
                ]);
            }

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }

    public function exportPayoutsCsv(Request $request): StreamedResponse
    {
        $payouts = Payout::with(['payeeUser', 'match'])->orderByDesc('created_at')->get();

        $filename = 'SAPRF_Payouts_' . now()->format('Y-m-d') . '.csv';

        return response()->streamDownload(function () use ($payouts) {
            $handle = fopen('php://output', 'w');

            fputcsv($handle, [
                'Reference', 'Type', 'Payee', 'Match', 'Gross', 'Fees',
                'Net', 'Paid', 'Outstanding', 'Status', 'Paid At',
            ]);

            foreach ($payouts as $p) {
                fputcsv($handle, [
                    $p->reference,
                    ucfirst(str_replace('_', ' ', $p->payee_type)),
                    $p->payeeUser?->name ?? 'SAPRF',
                    $p->match?->name ?? '-',
                    $p->gross_amount,
                    $p->fees_deducted,
                    $p->net_amount,
                    $p->paid_amount,
                    $p->outstandingBalance(),
                    ucfirst($p->status),
                    $p->paid_at?->format('Y-m-d H:i') ?? '-',
                ]);
            }

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }

    public function exportDashboardPdf(Request $request): Response
    {
        [$from, $to] = $this->parseDateRange($request);
        $summary = $this->financials->platformSummary($from, $to);
        $matchBreakdown = $this->financials->revenueByMatch($from, $to);

        $pdf = app('dompdf.wrapper');
        $pdf->loadView('financials.pdf.dashboard', compact('summary', 'matchBreakdown', 'from', 'to'));
        $pdf->setPaper('a4', 'landscape');

        return $pdf->download('SAPRF_Financial_Report_' . now()->format('Y-m-d') . '.pdf');
    }

    // ── Helpers ──

    private function parseDateRange(Request $request): array
    {
        $from = $request->filled('from') ? \Carbon\Carbon::parse($request->input('from')) : null;
        $to = $request->filled('to') ? \Carbon\Carbon::parse($request->input('to')) : null;

        if ($request->input('period') === 'month') {
            $from = now()->startOfMonth();
            $to = now()->endOfMonth();
        } elseif ($request->input('period') === 'season') {
            $year = $request->input('season_year', now()->year);
            $from = \Carbon\Carbon::create($year, 1, 1);
            $to = \Carbon\Carbon::create($year, 12, 31);
        }

        return [$from, $to];
    }
}
