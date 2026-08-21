<?php

namespace App\Http\Controllers;

use App\Enums\LadderVariable;
use App\Models\LadderSession;
use App\Services\Ladder\LadderAnalysis;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

class LadderSessionController extends Controller
{
    public function index(Request $request): View
    {
        $sessions = LadderSession::forUser($request->user()->id)
            ->with(['barrel', 'ammoLoad'])
            ->withCount('steps')
            ->orderByDesc('fired_on')
            ->orderByDesc('created_at')
            ->get();

        return view('ladder-sessions.index', compact('sessions'));
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'variable' => ['required', 'string', 'in:charge_weight,seating_depth'],
            'fired_on' => ['required', 'date'],
        ]);

        $session = LadderSession::create([
            'user_id' => $request->user()->id,
            'name' => $validated['name'],
            'variable' => LadderVariable::from($validated['variable']),
            'fired_on' => $validated['fired_on'],
        ]);

        return redirect()->route('ladder-sessions.show', $session);
    }

    public function show(LadderSession $ladderSession): View
    {
        $this->authorize('view', $ladderSession);

        return view('ladder-sessions.show', ['session' => $ladderSession]);
    }

    public function destroy(LadderSession $ladderSession): RedirectResponse
    {
        $this->authorize('delete', $ladderSession);

        $ladderSession->delete();

        return redirect()->route('ladder-sessions.index')
            ->with('success', "Ladder '{$ladderSession->name}' removed.");
    }

    /**
     * Per-step CSV of the same table the analysis view renders. Kept flat so
     * spreadsheet software will treat each shot as a column — a shooter can
     * export a session and open it directly.
     */
    public function exportCsv(LadderSession $ladderSession): Response
    {
        $this->authorize('view', $ladderSession);

        $result = LadderAnalysis::analyze($ladderSession);
        $variable = $result->variable;

        $rows = [];
        // Metadata header
        $rows[] = ['Ladder', $ladderSession->name];
        $rows[] = ['Variable', $variable->label()];
        $rows[] = ['Fired on', optional($ladderSession->fired_on)->toDateString()];
        $rows[] = ['Pooled SD (fps)', $result->pooledSd !== null ? number_format($result->pooledSd, 3, '.', '') : ''];
        $rows[] = ['Pooled df', $result->pooledDf ?? ''];
        $rows[] = ['Fitted slope ('.$variable->slopeUnit().')', $result->trend?->slope !== null ? number_format($result->trend->slope, 3, '.', '') : ''];
        $rows[] = ['Fitted intercept', $result->trend?->intercept !== null ? number_format($result->trend->intercept, 3, '.', '') : ''];
        $rows[] = [];

        // Per-step table
        $rows[] = [
            $variable->axisLabel(),
            'n',
            'mean (fps)',
            'sd (fps)',
            'sd CI lower',
            'sd CI upper',
            'ES (fps)',
            'in fit',
            'residual (fps)',
            'shots (fps)',
        ];
        foreach ($result->steps as $step) {
            $rows[] = [
                number_format($step->value, 3, '.', ''),
                $step->n,
                $step->n > 0 ? number_format($step->mean, 2, '.', '') : '',
                $step->sd !== null ? number_format($step->sd, 3, '.', '') : '',
                $step->sdCiLower !== null ? number_format($step->sdCiLower, 3, '.', '') : '',
                $step->sdCiUpper !== null ? number_format($step->sdCiUpper, 3, '.', '') : '',
                $step->es !== null ? number_format($step->es, 2, '.', '') : '',
                $step->includeInFit ? 'yes' : 'no',
                isset($result->residuals[$step->stepId]) ? number_format($result->residuals[$step->stepId], 2, '.', '') : '',
                implode(' ', array_map(fn ($v) => number_format($v, 1, '.', ''), $step->velocities)),
            ];
        }

        // Pair table
        $rows[] = [];
        $rows[] = ['Adjacent comparisons'];
        $rows[] = ['from', 'to', 'd', 'se_d', 't', 'df', 'p', 'classification', 'step slope'];
        foreach ($result->pairs as $p) {
            $rows[] = [
                number_format($p->fromValue, 3, '.', ''),
                number_format($p->toValue, 3, '.', ''),
                number_format($p->d, 3, '.', ''),
                number_format($p->seD, 3, '.', ''),
                number_format($p->t, 3, '.', ''),
                number_format($p->df, 2, '.', ''),
                number_format($p->p, 4, '.', ''),
                $p->classification->value,
                number_format($p->stepSlope, 3, '.', ''),
            ];
        }

        $csv = '';
        foreach ($rows as $row) {
            $csv .= implode(',', array_map([self::class, 'csvEscape'], $row))."\r\n";
        }

        $filename = 'ladder-'.$ladderSession->id.'-'.now()->format('Ymd-His').'.csv';

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
