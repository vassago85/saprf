<?php

namespace App\Http\Controllers;

use App\Models\MatchEvent;
use App\Services\PostMatchReport;
use Illuminate\Http\RedirectResponse;
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
}
