<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreScoreImportRequest;
use App\Jobs\ProcessScoreImportJob;
use App\Models\MatchEvent;
use App\Models\Score;
use App\Models\ScoreImport;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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

    public function create(): View
    {
        $matches = MatchEvent::orderBy('match_date', 'desc')->get(['id', 'name', 'match_date']);

        return view('score-imports.create', compact('matches'));
    }

    public function store(StoreScoreImportRequest $request): RedirectResponse
    {
        $file = $request->file('file');
        $path = $file->store('score-imports', 'local');

        $import = ScoreImport::query()->create([
            'match_id' => $request->validated('match_id'),
            'uploaded_by' => $request->user()->id,
            'source_type' => $request->validated('source_type'),
            'original_filename' => $file->getClientOriginalName(),
            'import_status' => 'queued',
        ]);

        ProcessScoreImportJob::dispatch($import->id, storage_path("app/{$path}"));

        return redirect()->route('score-imports.show', $import)
            ->with('success', 'Score import queued for processing.');
    }

    public function show(ScoreImport $scoreImport): View
    {
        $scoreImport->load(['match', 'uploader']);

        $scores = Score::where('score_import_id', $scoreImport->id)
            ->with('user')
            ->orderBy('placement')
            ->paginate(30);

        return view('score-imports.show', compact('scoreImport', 'scores'));
    }
}
