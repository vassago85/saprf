<?php

namespace App\Http\Controllers;

use App\Models\Membership;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SascocReportController extends Controller
{
    /**
     * Exact column order of the SASCOC federation membership template.
     *
     * @var array<int, string>
     */
    private const TEMPLATE_HEADERS = [
        'FEDERATION',
        'SURNAME',
        'NAME',
        'Date of Birth (yyyy/mm/dd)',
        'ID NUMBER',
        'Email address',
        'GENDER (M/F)',
        'Junior (J) / Senior (S)',
        'Scholar / Student',
        'Athlete',
        'Disabled (Y/N)',
        'Armed forces (Y/N)',
        'PDI (Y/N)',
        'ETHNICITY B, C, I, W (Black, Coloured, Indian, White)',
        'PROVINCE',
        'District',
        'Club',
        'Amount',
        'Role (Non Athletes)',
    ];

    public function index(Request $request): View
    {
        $year = $request->input('year', (string) now()->year);
        $includeExpired = $request->boolean('include_expired');
        $years = range(now()->year, now()->year - 5);

        [$seniorPrice, $juniorPrice, $issueDate] = $this->reportParams($request);

        $members = $this->getMembers($year, $includeExpired);

        $juniorCount = 0;
        $seniorCount = 0;
        foreach ($members as $membership) {
            if ($this->isJunior($membership->user, $issueDate)) {
                $juniorCount++;
            } else {
                $seniorCount++;
            }
        }
        $total = $seniorCount * $seniorPrice + $juniorCount * $juniorPrice;

        return view('sascoc-report.index', [
            'year' => $year,
            'years' => $years,
            'includeExpired' => $includeExpired,
            'memberCount' => $members->count(),
            'seniorCount' => $seniorCount,
            'juniorCount' => $juniorCount,
            'seniorPrice' => $seniorPrice,
            'juniorPrice' => $juniorPrice,
            'issueDate' => $issueDate->format('Y-m-d'),
            'total' => $total,
            'missingIdCount' => $members->filter(fn ($m) => empty($m->user?->sa_id_number))->count(),
        ]);
    }

    public function downloadExcel(Request $request): StreamedResponse
    {
        $year = $request->input('year', (string) now()->year);
        $includeExpired = $request->boolean('include_expired');
        [$seniorPrice, $juniorPrice, $issueDate] = $this->reportParams($request);

        $members = $this->getMembers($year, $includeExpired);
        $filename = "SAPRF_Federation_Report_{$year}.csv";

        return response()->streamDownload(function () use ($members, $seniorPrice, $juniorPrice, $issueDate) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, self::TEMPLATE_HEADERS);

            $seniorCount = 0;
            $juniorCount = 0;
            foreach ($members as $membership) {
                $isJunior = $this->isJunior($membership->user, $issueDate);
                $isJunior ? $juniorCount++ : $seniorCount++;
                fputcsv($handle, $this->templateRow($membership, $isJunior ? $juniorPrice : $seniorPrice, $issueDate));
            }

            // Summary block (mirrors the totals shown on their template).
            fputcsv($handle, []);
            fputcsv($handle, ['Summary', '', '', '', '', '', 'Count', 'Price', 'Subtotal']);
            fputcsv($handle, ['Seniors', '', '', '', '', '', $seniorCount, number_format($seniorPrice, 2, '.', ''), number_format($seniorCount * $seniorPrice, 2, '.', '')]);
            fputcsv($handle, ['Juniors', '', '', '', '', '', $juniorCount, number_format($juniorPrice, 2, '.', ''), number_format($juniorCount * $juniorPrice, 2, '.', '')]);
            fputcsv($handle, ['Total', '', '', '', '', '', $seniorCount + $juniorCount, '', number_format($seniorCount * $seniorPrice + $juniorCount * $juniorPrice, 2, '.', '')]);

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
        [$seniorPrice, $juniorPrice, $issueDate] = $this->reportParams($request);

        $members = $this->getMembers($year, $includeExpired);

        $seniorCount = 0;
        $juniorCount = 0;
        $rows = [];
        foreach ($members as $membership) {
            $isJunior = $this->isJunior($membership->user, $issueDate);
            $isJunior ? $juniorCount++ : $seniorCount++;
            $rows[] = $this->templateRow($membership, $isJunior ? $juniorPrice : $seniorPrice, $issueDate);
        }

        $pdf = app('dompdf.wrapper');
        $pdf->loadView('sascoc-report.pdf', [
            'year' => $year,
            'headers' => self::TEMPLATE_HEADERS,
            'rows' => $rows,
            'seniorCount' => $seniorCount,
            'juniorCount' => $juniorCount,
            'seniorPrice' => $seniorPrice,
            'juniorPrice' => $juniorPrice,
            'total' => $seniorCount * $seniorPrice + $juniorCount * $juniorPrice,
            'generatedAt' => now()->format('d M Y H:i'),
        ]);
        $pdf->setPaper('a4', 'landscape');

        return $pdf->download("SAPRF_Federation_Report_{$year}.pdf");
    }

    /**
     * @return array{0: float, 1: float, 2: Carbon}
     */
    private function reportParams(Request $request): array
    {
        $seniorPrice = (float) $request->input('senior_price', 130);
        $juniorPrice = (float) $request->input('junior_price', 35);

        try {
            $issueDate = Carbon::parse($request->input('issue_date') ?: now());
        } catch (\Throwable) {
            $issueDate = Carbon::now();
        }

        return [$seniorPrice, $juniorPrice, $issueDate];
    }

    /**
     * Junior = under 18 on the date of issue. Anyone else (or unknown DOB) is a senior.
     */
    private function isJunior(?User $user, Carbon $issueDate): bool
    {
        $age = $user?->getAgeOn($issueDate->copy());

        return $age !== null && $age < 18;
    }

    /**
     * @return array<int, string|float>
     */
    private function templateRow(Membership $membership, float $amount, Carbon $issueDate): array
    {
        $user = $membership->user;
        [$surname, $firstName] = $this->splitName($user?->name ?? '');

        return [
            'SAPRF',
            $surname,
            $firstName,
            $user?->date_of_birth?->format('Y/m/d') ?? '',
            $user?->sa_id_number ?? '',
            $user?->email ?? '',
            $this->genderFromId($user?->sa_id_number),
            $this->isJunior($user, $issueDate) ? 'J' : 'S',
            '', // Scholar / Student
            '', // Athlete
            'N', // Disabled
            '', // Armed forces
            '', // PDI
            '', // Ethnicity
            $user?->province?->name ?? '',
            '', // District
            $user?->club?->name ?? '',
            number_format($amount, 2, '.', ''),
            '', // Role (Non Athletes)
        ];
    }

    /**
     * @return array{0: string, 1: string} [surname, first name(s)]
     */
    private function splitName(string $full): array
    {
        $parts = preg_split('/\s+/', trim($full)) ?: [];
        $parts = array_values(array_filter($parts, fn ($p) => $p !== ''));

        if ($parts === []) {
            return ['', ''];
        }
        if (count($parts) === 1) {
            return [$parts[0], ''];
        }

        $surname = array_pop($parts);

        return [$surname, implode(' ', $parts)];
    }

    /**
     * SA ID gender: sequence digits (7-10) >= 5000 => male, else female.
     */
    private function genderFromId(?string $id): string
    {
        if (! $id || ! preg_match('/^\d{10,13}$/', $id)) {
            return '';
        }

        return ((int) substr($id, 6, 4)) >= 5000 ? 'M' : 'F';
    }

    private function getMembers(string $year, bool $includeExpired)
    {
        return Membership::query()
            ->where('payment_status', 'paid')
            ->whereNotIn('status', ['revoked', 'pending'])
            ->when($includeExpired, function ($q) {
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
            ->with(['user.province', 'user.club'])
            ->orderBy('saprf_number')
            ->get();
    }
}
