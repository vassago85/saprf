<?php

namespace App\Http\Controllers;

use App\Models\AmmoString;
use App\Services\AmmoString\StringAnalysis;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

class AmmoStringController extends Controller
{
    public function index(Request $request): View
    {
        $strings = AmmoString::forUser($request->user()->id)
            ->with(['ammoLoad', 'barrel', 'ladderSession'])
            ->withCount('shots')
            ->orderByDesc('fired_on')
            ->orderByDesc('created_at')
            ->get();

        return view('ammo-strings.index', compact('strings'));
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'label' => ['required', 'string', 'max:120'],
            'fired_on' => ['required', 'date'],
            'ammo_load_id' => ['nullable', 'integer', 'exists:ammo_loads,id'],
            'barrel_id' => ['nullable', 'integer', 'exists:barrels,id'],
            'ladder_session_id' => ['nullable', 'integer', 'exists:ladder_sessions,id'],
        ]);

        $string = AmmoString::create([
            'user_id' => $request->user()->id,
            'label' => $validated['label'],
            'fired_on' => $validated['fired_on'],
            'ammo_load_id' => $validated['ammo_load_id'] ?? null,
            'barrel_id' => $validated['barrel_id'] ?? null,
            'ladder_session_id' => $validated['ladder_session_id'] ?? null,
        ]);

        return redirect()->route('ammo-strings.show', $string);
    }

    public function show(AmmoString $ammoString): View
    {
        $this->authorize('view', $ammoString);

        return view('ammo-strings.show', ['string' => $ammoString]);
    }

    public function destroy(AmmoString $ammoString): RedirectResponse
    {
        $this->authorize('delete', $ammoString);

        $ammoString->delete();

        return redirect()->route('ammo-strings.index')
            ->with('success', "String '{$ammoString->label}' removed.");
    }

    /**
     * Full-fidelity CSV of the string and its analysis. Kept flat so it opens
     * cleanly in a spreadsheet; one row per shot plus a metadata header and
     * a findings block.
     */
    public function exportCsv(AmmoString $ammoString): Response
    {
        $this->authorize('view', $ammoString);

        $result = StringAnalysis::analyze($ammoString);

        $rows = [];
        $rows[] = ['String', $ammoString->label];
        $rows[] = ['Fired on', optional($ammoString->fired_on)->toDateString()];
        $rows[] = ['Ammo load', $ammoString->ammoLoad?->displayName() ?? ''];
        $rows[] = ['Barrel', $ammoString->barrel?->label ?? ''];
        $rows[] = ['n', $result->n];
        $rows[] = ['Mean (fps)', $result->mean !== null ? number_format($result->mean, 2, '.', '') : ''];
        $rows[] = ['SD (fps)', $result->sd !== null ? number_format($result->sd, 3, '.', '') : ''];
        $rows[] = ['SD 90% CI lower', $result->sdCiLower !== null ? number_format($result->sdCiLower, 3, '.', '') : ''];
        $rows[] = ['SD 90% CI upper', $result->sdCiUpper !== null ? number_format($result->sdCiUpper, 3, '.', '') : ''];
        $rows[] = ['ES (fps)', $result->es !== null ? number_format($result->es, 2, '.', '') : ''];
        $rows[] = ['Trend slope (fps/shot)', $result->trend?->slope !== null ? number_format($result->trend->slope, 4, '.', '') : ''];
        $rows[] = ['Trend p-value', $result->trend?->slopeP !== null ? number_format($result->trend->slopeP, 4, '.', '') : ''];
        $rows[] = ['Cold-bore delta (fps)', $result->coldBoreDelta !== null ? number_format($result->coldBoreDelta, 2, '.', '') : ''];
        $rows[] = [];

        $rows[] = ['sequence', 'velocity (fps)', 'excluded', 'residual from mean', 'residual from trend'];
        foreach ($result->shots as $shot) {
            $rows[] = [
                $shot['sequence'],
                number_format($shot['velocity'], 1, '.', ''),
                $shot['excluded'] ? 'yes' : 'no',
                $shot['residualFromMean'] !== null ? number_format($shot['residualFromMean'], 2, '.', '') : '',
                $shot['residualFromTrend'] !== null ? number_format($shot['residualFromTrend'], 2, '.', '') : '',
            ];
        }

        $rows[] = [];
        $rows[] = ['Findings'];
        foreach ($result->findings as $finding) {
            $rows[] = [$finding->severity, $finding->title, strip_tags($finding->body)];
        }

        $csv = '';
        foreach ($rows as $row) {
            $csv .= implode(',', array_map([self::class, 'csvEscape'], $row))."\r\n";
        }

        $filename = 'string-'.$ammoString->id.'-'.now()->format('Ymd-His').'.csv';

        return response($csv, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ]);
    }

    private static function csvEscape(mixed $value): string
    {
        $str = (string) $value;
        if (str_contains($str, ',') || str_contains($str, '"') || str_contains($str, "\n") || str_contains($str, "\r")) {
            $str = '"'.str_replace('"', '""', $str).'"';
        }

        return $str;
    }
}
