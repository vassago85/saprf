<?php

namespace App\Http\Controllers;

use App\Models\MatchEvent;
use App\Services\PostMatchReport;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use Illuminate\View\View;

class PostMatchReportController extends Controller
{
    public function __construct(
        private readonly PostMatchReport $report,
    ) {}

    public function show(MatchEvent $match): View|RedirectResponse
    {
        $this->authorize('view', $match);

        if ($match->series_level !== 'final') {
            return redirect()
                ->route('matches.overview', $match)
                ->with('error', 'The post-match championship report is only available on SA Championship matches.');
        }

        return view('matches.post-match-report', $this->report->build($match));
    }

    /**
     * PDF rendering of the same post-match report. Same gate as `show`: only
     * a `final`-level match is allowed; anything else bounces back to the
     * overview so a stray link does not dead-end on a 404.
     */
    public function pdf(MatchEvent $match): Response|RedirectResponse
    {
        $this->authorize('view', $match);

        if ($match->series_level !== 'final') {
            return redirect()
                ->route('matches.overview', $match)
                ->with('error', 'The post-match championship report is only available on SA Championship matches.');
        }

        $data = $this->report->build($match);
        $filename = sprintf(
            'SAPRF-%s-%s-Championship-Report-%s.pdf',
            Str::slug($data['series']),
            $data['season'],
            $match->match_date?->format('Y-m-d') ?? now()->format('Y-m-d'),
        );

        return Pdf::loadView('matches.post-match-report-pdf', $data)
            ->setPaper('a4', 'portrait')
            ->download($filename);
    }
}
