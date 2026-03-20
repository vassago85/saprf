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
        $years = range(now()->year, now()->year - 5);

        $members = $this->getActiveMembers($year);
        $missingIdCount = $members->filter(fn ($m) => empty($m->user?->sa_id_number))->count();

        return view('sascoc-report.index', [
            'year' => $year,
            'years' => $years,
            'memberCount' => $members->count(),
            'missingIdCount' => $missingIdCount,
        ]);
    }

    public function downloadExcel(Request $request): StreamedResponse
    {
        $year = $request->input('year', (string) now()->year);
        $members = $this->getActiveMembers($year);

        $filename = "SASCOC_Active_Members_{$year}.csv";

        return response()->streamDownload(function () use ($members) {
            $handle = fopen('php://output', 'w');

            fputcsv($handle, [
                'SAPRF Number', 'Full Name', 'SA ID Number', 'Email',
                'Phone', 'Province', 'Membership Type', 'Start Date', 'Expiry Date',
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
                    $membership->membership_type,
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
        $members = $this->getActiveMembers($year);

        $pdf = app('dompdf.wrapper');
        $pdf->loadView('sascoc-report.pdf', [
            'year' => $year,
            'members' => $members,
            'generatedAt' => now()->format('d M Y H:i'),
        ]);
        $pdf->setPaper('a4', 'landscape');

        return $pdf->download("SASCOC_Active_Members_{$year}.pdf");
    }

    private function getActiveMembers(string $year)
    {
        return Membership::query()
            ->where('status', 'active')
            ->where('membership_type', 'paid')
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
