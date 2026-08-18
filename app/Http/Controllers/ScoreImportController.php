<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreScoreImportRequest;
use App\Jobs\ProcessScoreImportJob;
use App\Models\MatchEvent;
use App\Models\Score;
use App\Models\ScoreImport;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
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

        $matches = $query->get(['id', 'name', 'match_date', 'match_end_date']);

        // Build a lookup used by the front-end to know which matches are 2-day
        // so we can toggle the Day 1 / Day 2 picker on the fly.
        $matchMeta = $matches->mapWithKeys(fn ($m) => [
            $m->id => [
                'is_two_day' => $m->isMultiDay(),
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

        $matchId = (int) $request->validated('match_id');

        // If admin ticked "replace existing", wipe out prior scores for this match
        // so the import starts from a clean slate. This is what you want when
        // re-uploading the definitive results file for a match.
        $replaceExisting = $request->boolean('replace_existing');
        if ($replaceExisting) {
            DB::transaction(function () use ($matchId) {
                Score::where('match_id', $matchId)->delete();
            });
        }

        $day = $request->filled('day') ? (int) $request->validated('day') : null;

        $import = ScoreImport::query()->create([
            'match_id' => $matchId,
            'uploaded_by' => $request->user()->id,
            'source_type' => $request->validated('source_type'),
            'day' => $day,
            'original_filename' => $file->getClientOriginalName(),
            'import_status' => 'queued',
            'notes' => $replaceExisting ? 'Existing scores for this match were cleared before import.' : null,
        ]);

        ProcessScoreImportJob::dispatch($import->id, $absolutePath);

        return redirect()->route('score-imports.show', $import)
            ->with('success', $replaceExisting
                ? 'Existing scores cleared. Import queued for processing.'
                : 'Score import queued for processing.');
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

    /**
     * Download a blank CSV template with the recommended column layout,
     * so admins re-uploading historical data start from a known-good format.
     */
    public function template(): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        return response()->streamDownload(function () {
            $handle = fopen('php://output', 'w');

            // Header row — column names the importer understands out of the box
            fputcsv($handle, [
                'shooter_name',
                'email',
                'raw_score',
                'placement',
                'division',
                'stage_1',
                'stage_2',
                'stage_3',
                'stage_4',
                'stage_5',
                'stage_6',
                'stage_7',
                'stage_8',
                'stage_9',
                'stage_10',
            ]);

            // Example rows to show format
            fputcsv($handle, [
                'John Smith',
                'john.smith@example.co.za',
                '58.4',
                '1',
                'open',
                '6.2', '5.8', '6.0', '5.9', '5.7', '5.9', '5.8', '5.7', '5.7', '5.7',
            ]);
            fputcsv($handle, [
                'Jane Doe',
                'jane.doe@example.co.za',
                '54.1',
                '2',
                'ladies',
                '5.5', '5.4', '5.3', '5.5', '5.4', '5.4', '5.3', '5.4', '5.4', '5.5',
            ]);

            fclose($handle);
        }, 'SAPRF_Score_Import_Template.csv', ['Content-Type' => 'text/csv']);
    }
}
