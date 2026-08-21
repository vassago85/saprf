<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreScoreImportRequest;
use App\Jobs\ProcessScoreImportJob;
use App\Models\MatchEvent;
use App\Models\Score;
use App\Models\ScoreImport;
use App\Models\ShooterLog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

class ScoreImportController extends Controller
{
    public function index(Request $request): View
    {
        $user = $request->user();

        $scoreImports = $user->hasAnyRole(['owner', 'admin'])
            ? ScoreImport::query()->with(['match', 'uploader'])->latest()->paginate(20)
            : $user->scoreImports()->with('match')->latest()->paginate(20);

        return view('score-imports.index', compact('scoreImports'));
    }

    public function create(Request $request): View
    {
        $user = $request->user();
        $query = MatchEvent::query()->orderBy('match_date', 'desc');

        if ($user->hasRole('match_director') && ! $user->hasAnyRole(['developer', 'owner', 'admin'])) {
            $query->where('created_by', $user->id);
        }

        $matches = $query->get([
            'id', 'name', 'match_date', 'match_end_date', 'series_level',
        ]);

        // Front-end toggles Day 1 / Overall only for 2-day nationals.
        $matchMeta = $matches->mapWithKeys(fn (MatchEvent $m) => [
            $m->id => [
                'is_two_day' => $m->isMultiDay(),
                'is_two_day_national' => $m->isTwoDayNational(),
                'end' => $m->match_end_date?->toDateString(),
            ],
        ])->toArray();

        return view('score-imports.create', [
            'matches' => $matches,
            'matchMeta' => $matchMeta,
            'preselectedMatchId' => $request->integer('match_id') ?: null,
        ]);
    }

    public function store(StoreScoreImportRequest $request): RedirectResponse
    {
        $file = $request->file('file');
        $path = $file->store('score-imports', 'local');
        $absolutePath = Storage::disk('local')->path($path);

        $selectedMatch = MatchEvent::query()->findOrFail((int) $request->validated('match_id'));
        $scoreScope = $request->validated('score_scope');

        // Day 1 on a 2-day national → sibling provincial; Overall → national.
        $targetMatch = $selectedMatch;
        $notes = [];

        if ($selectedMatch->isTwoDayNational() && $scoreScope === 'day1') {
            $targetMatch = $selectedMatch->findOrCreateProvincialDay1Sibling();
            $notes[] = "Day 1 scores imported onto provincial sibling #{$targetMatch->id} ({$targetMatch->name}).";
        } elseif ($selectedMatch->isTwoDayNational() && $scoreScope === 'overall') {
            $notes[] = 'Overall scores imported onto the national match.';
        }

        // Replace only clears scores on the target match (sibling or national).
        // shooter_logs.score_id is ON DELETE RESTRICT, so drop logs first.
        $replaceExisting = $request->boolean('replace_existing');
        if ($replaceExisting) {
            DB::transaction(function () use ($targetMatch) {
                $scoreIds = Score::where('match_id', $targetMatch->id)->pluck('id');
                ShooterLog::whereIn('score_id', $scoreIds)->delete();
                ShooterLog::where('match_id', $targetMatch->id)->delete();
                Score::where('match_id', $targetMatch->id)->delete();
            });
            $notes[] = 'Existing scores for the target match were cleared before import.';
        }

        // Both Day 1 and Overall use the single-total path (day = null).
        // Legacy day=1|2 param is only meaningful on non-2-day-national matches
        // (i.e. some external caller merging split day CSVs onto a single match).
        $day = null;
        if (! $selectedMatch->isTwoDayNational() && $request->filled('day')) {
            $day = (int) $request->validated('day');
        }

        $import = ScoreImport::query()->create([
            'match_id' => $targetMatch->id,
            'uploaded_by' => $request->user()->id,
            'source_type' => $request->validated('source_type'),
            'day' => $day,
            'original_filename' => $file->getClientOriginalName(),
            'import_status' => 'queued',
            'notes' => $notes !== [] ? implode(' ', $notes) : null,
        ]);

        ProcessScoreImportJob::dispatch($import->id, $absolutePath);

        $success = $scoreScope === 'day1' && $selectedMatch->isTwoDayNational()
            ? "Day 1 import queued onto {$targetMatch->name}."
            : ($replaceExisting
                ? 'Existing scores cleared. Import queued for processing.'
                : 'Score import queued for processing.');

        return redirect()->route('score-imports.show', $import)
            ->with('success', $success);
    }

    public function show(Request $request, ScoreImport $scoreImport): View
    {
        $scoreImport->load(['match', 'uploader']);

        $scores = Score::where('score_import_id', $scoreImport->id)
            ->with('user')
            ->orderBy('placement')
            ->paginate(30);

        // Feeds the "complete match & request payout" prompt on the show page.
        // The MD who owns this match can offer to close it out and file for
        // payment straight from the successful-import screen, but only when
        // the match isn't already completed and no payout has been requested
        // yet.
        $match = $scoreImport->match;
        $user = $request->user();
        $viewerOwnsMatch = $user && ($match->created_by === $user->id
            || $user->hasAnyRole(['owner', 'admin', 'exco', 'developer']));

        $canRequestMdPayout = $viewerOwnsMatch
            && $scoreImport->import_status === 'completed'
            && $match->status !== 'completed'
            && ! $match->payouts()->where('payee_type', 'match_director')->exists();

        return view('score-imports.show', compact(
            'scoreImport', 'scores', 'canRequestMdPayout',
        ));
    }

    public function template(Request $request): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $variant = $request->query('variant', 'basic');

        return match ($variant) {
            'detailed' => $this->detailedTemplate(),
            'impact' => $this->impactTemplate(),
            default => $this->basicTemplate(),
        };
    }

    /**
     * Totals-only CSV: the minimum an MD needs for Day 1 or Overall imports.
     * No stage columns — one score per shooter.
     */
    private function basicTemplate(): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        return response()->streamDownload(function () {
            $handle = fopen('php://output', 'w');

            fputcsv($handle, [
                'shooter_name',
                'email',
                'raw_score',
                'placement',
                'division',
            ]);

            fputcsv($handle, ['John Smith', 'john.smith@example.co.za', '58.4', '1', 'open']);
            fputcsv($handle, ['Jane Doe', 'jane.doe@example.co.za', '54.1', '2', 'ladies']);
            fputcsv($handle, ['Piet van der Merwe', '', '49.7', '3', 'senior']);

            fclose($handle);
        }, 'SAPRF_Score_Import_Template_Basic.csv', ['Content-Type' => 'text/csv']);
    }

    /**
     * Detailed CSV with per-stage columns. Optional — only useful when the MD
     * wants the individual stage points recorded alongside the total.
     */
    private function detailedTemplate(): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        return response()->streamDownload(function () {
            $handle = fopen('php://output', 'w');

            fputcsv($handle, [
                'shooter_name',
                'email',
                'raw_score',
                'placement',
                'division',
                'stage_1', 'stage_2', 'stage_3', 'stage_4', 'stage_5',
                'stage_6', 'stage_7', 'stage_8', 'stage_9', 'stage_10',
            ]);

            fputcsv($handle, [
                'John Smith', 'john.smith@example.co.za', '58.4', '1', 'open',
                '6.2', '5.8', '6.0', '5.9', '5.7', '5.9', '5.8', '5.7', '5.7', '5.7',
            ]);
            fputcsv($handle, [
                'Jane Doe', 'jane.doe@example.co.za', '54.1', '2', 'ladies',
                '5.5', '5.4', '5.3', '5.5', '5.4', '5.4', '5.3', '5.4', '5.4', '5.5',
            ]);

            fclose($handle);
        }, 'SAPRF_Score_Import_Template_Detailed.csv', ['Content-Type' => 'text/csv']);
    }

    /**
     * Impact-scoring style example — the importer normalises these column
     * names automatically. Handy when an MD has an Impact export and wants
     * to confirm it will load without reshaping.
     */
    private function impactTemplate(): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        return response()->streamDownload(function () {
            $handle = fopen('php://output', 'w');

            fputcsv($handle, [
                'First', 'Last', 'Member Number', 'Impacts', 'Match Percentage', 'Place', 'Div',
            ]);

            fputcsv($handle, ['John', 'Smith', 'SAPRF-2026-0165', '58', '100.0', '1', 'Open']);
            fputcsv($handle, ['Jane', 'Doe', 'SAPRF-2026-0212', '54', '93.1', '2', 'Ladies']);
            fputcsv($handle, ['Piet', 'van der Merwe', '', '50', '86.2', '3', 'Seniors']);

            fclose($handle);
        }, 'SAPRF_Score_Import_Template_Impact.csv', ['Content-Type' => 'text/csv']);
    }
}
