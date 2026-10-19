<?php

namespace App\Http\Controllers;

use App\Models\Payout;
use App\Services\PayoutStatementRenderer;
use Illuminate\Contracts\Support\Responsable;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * MD-facing payout portal (outside admin Finance).
 *
 * Scope rules:
 *   - A match director sees only payouts where `payee_user_id == auth->id`.
 *   - Finance / Exco / Owner / Admin / Developer see every MD payout.
 *   - No cross-MD leakage — enforced by {@see \App\Policies\PayoutPolicy}.
 */
class MyPayoutController extends Controller
{
    public function __construct(private readonly PayoutStatementRenderer $renderer) {}

    public function index(Request $request): View
    {
        $user = $request->user();
        $isFinance = $user->hasAnyRole(['developer', 'exco', 'owner', 'admin']);

        $payouts = Payout::query()
            ->with(['payeeUser', 'match', 'creator'])
            ->where('payee_type', 'match_director')
            ->when(! $isFinance, fn ($q) => $q->where('payee_user_id', $user->id))
            ->orderByDesc('created_at')
            ->paginate(25);

        return view('my-payouts.index', [
            'payouts' => $payouts,
            'isFinance' => $isFinance,
        ]);
    }

    public function show(Request $request, Payout $payout): View
    {
        $this->authorize('view', $payout);

        $payout->load(['items' => fn ($q) => $q->orderBy('sort_order'), 'match', 'payeeUser']);
        $payments = $payout->paymentHistory();
        $summary = $payout->statement_snapshot['summary'] ?? [];
        $meta = $payout->statement_snapshot['match'] ?? [];

        return view('my-payouts.show', [
            'payout' => $payout,
            'payments' => $payments,
            'summary' => $summary,
            'meta' => $meta,
            'paidItems' => $payout->items->where('excluded', false)->values(),
            'excludedItems' => $payout->items->where('excluded', true)->values(),
        ]);
    }

    // Returns Responsable because Spatie\LaravelPdf\PdfBuilder (what the
    // renderer hands back) is Responsable, not an Illuminate\Http\Response.
    // Laravel's router calls toResponse($request) on it, which only then
    // produces the PDF-bytes BinaryFileResponse. Typing this as Response
    // would turn a working download into a 500 at the type boundary.
    public function downloadPdf(Request $request, Payout $payout): Responsable
    {
        $this->authorize('view', $payout);

        return $this->renderer->pdfDownload($payout);
    }

    public function downloadCsv(Request $request, Payout $payout): StreamedResponse
    {
        $this->authorize('view', $payout);

        return $this->renderer->csvDownload($payout);
    }
}
