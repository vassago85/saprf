<?php

namespace App\Http\Controllers;

use App\Models\Membership;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SascocReportController extends Controller
{
    public function index(Request $request): View
    {
        $year = $request->input('year', (string) now()->year);
        $includeExpired = $request->boolean('include_expired');
        $years = range(now()->year, now()->year - 5);

        $members = $this->getMembers($year, $includeExpired);
        $activeCount = $members->where('status', 'active')->count();
        $expiredCount = $members->whereIn('status', ['expired', 'lapsed'])->count();
        $missingIdCount = $members->filter(fn ($m) => empty($m->user?->sa_id_number))->count();

        return view('sascoc-report.index', [
            'year' => $year,
            'years' => $years,
            'includeExpired' => $includeExpired,
            'memberCount' => $members->count(),
            'activeCount' => $activeCount,
            'expiredCount' => $expiredCount,
            'missingIdCount' => $missingIdCount,
        ]);
    }

    public function downloadExcel(Request $request): StreamedResponse
    {
        $year = $request->input('year', (string) now()->year);
        $includeExpired = $request->boolean('include_expired');
        $members = $this->getMembers($year, $includeExpired);

        $label = $includeExpired ? 'All_Members' : 'Active_Members';
        $filename = "SASCOC_{$label}_{$year}.csv";

        return response()->streamDownload(function () use ($members) {
            $handle = fopen('php://output', 'w');

            fputcsv($handle, [
                'SAPRF Number', 'Full Name', 'SA ID Number', 'Email',
                'Phone', 'Province', 'Membership Status', 'Payment Status',
                'Start Date', 'Expiry Date',
            ]);

            foreach ($members as $membership) {
                $user = $membership->user;
                fputcsv($handle, [
                    $membership->saprf_number,
                    $user?->name ?? '—',
                    $user?->sa_id_number ?? '',
                    $user?->email ?? '',
                    $user?->phone ?? '',
                    $user?->province?->name ?? '',
                    ucfirst($membership->status),
                    ucfirst($membership->payment_status),
                    $membership->start_date?->format('Y-m-d') ?? '',
                    $membership->expiry_date?->format('Y-m-d') ?? '',
                ]);
            }

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }

    public function downloadPdf(Request $request): Response
    {
        $year = $request->input('year', (string) now()->year);
        $includeExpired = $request->boolean('include_expired');
        $members = $this->getMembers($year, $includeExpired);

        $pdf = app('dompdf.wrapper');
        $pdf->loadView('sascoc-report.pdf', [
            'year' => $year,
            'members' => $members,
            'includeExpired' => $includeExpired,
            'generatedAt' => now()->format('d M Y H:i'),
        ]);
        $pdf->setPaper('a4', 'landscape');

        $label = $includeExpired ? 'All_Members' : 'Active_Members';

        return $pdf->download("SASCOC_{$label}_{$year}.pdf");
    }

    private function getMembers(string $year, bool $includeExpired)
    {
        return Membership::query()
            ->where('payment_status', 'paid')
            ->whereNotIn('status', ['revoked', 'pending'])
            ->when($includeExpired, function ($q) use ($year) {
                $q->whereIn('status', ['active', 'expired', 'lapsed']);
            }, function ($q) {
                $q->where('status', 'active');
            })
            ->whereNotNull('start_date')
            ->whereDate('start_date', '<=', "{$year}-12-31")
            ->where(function ($q) use ($year) {
                $q->whereDate('expiry_date', '>=', "{$year}-01-01")
                    ->orWhereNull('expiry_date');
            })
            ->with('user.province')
            ->orderBy('saprf_number')
            ->get();
    }
}
