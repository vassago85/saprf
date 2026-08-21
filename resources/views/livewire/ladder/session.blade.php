<?php

use App\Enums\LadderVariable;
use App\Models\AmmoLoad;
use App\Models\Barrel;
use App\Models\LadderSession;
use App\Models\LadderShot;
use App\Models\LadderStep;
use App\Services\Ladder\LadderAnalysis;
use Livewire\Volt\Component;

new class extends Component
{
    public LadderSession $session;

    // Editor state — metadata form
    public string $name = '';

    public string $variable = '';

    public ?int $barrel_id = null;

    public ?int $ammo_load_id = null;

    public string $fired_on = '';

    public string $notes = '';

    public string $temperature_c = '';

    public ?int $barrel_round_count_at_session = null;

    public string $powder = '';

    public string $bullet = '';

    public string $brass = '';

    public string $primer = '';

    // Paste box + resolving delta
    public string $paste = '';

    public float $resolvingDelta = 15.0;

    // Ephemeral form for adding one shot / step
    public string $newShotVelocity = '';

    public ?int $newShotStepId = null;

    public string $newStepValue = '';

    public function mount(LadderSession $session): void
    {
        // The controller has already authorised the request. Belt-and-braces
        // check here in case the component is ever wired up somewhere else.
        $this->authorize('view', $session);

        $this->session = $session;
        $this->syncFormFromModel();
    }

    protected function syncFormFromModel(): void
    {
        $this->name = $this->session->name;
        $this->variable = $this->session->variableEnum()->value;
        $this->barrel_id = $this->session->barrel_id;
        $this->ammo_load_id = $this->session->ammo_load_id;
        $this->fired_on = optional($this->session->fired_on)->toDateString() ?? '';
        $this->notes = (string) $this->session->notes;
        $this->temperature_c = $this->session->temperature_c !== null ? (string) $this->session->temperature_c : '';
        $this->barrel_round_count_at_session = $this->session->barrel_round_count_at_session;
        $this->powder = (string) $this->session->powder;
        $this->bullet = (string) $this->session->bullet;
        $this->brass = (string) $this->session->brass;
        $this->primer = (string) $this->session->primer;
    }

    public function saveMetadata(): void
    {
        $this->authorize('update', $this->session);

        $data = $this->validate([
            'name' => ['required', 'string', 'max:120'],
            'variable' => ['required', 'in:charge_weight,seating_depth'],
            'barrel_id' => ['nullable', 'integer'],
            'ammo_load_id' => ['nullable', 'integer'],
            'fired_on' => ['required', 'date'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'temperature_c' => ['nullable', 'numeric', 'between:-40,60'],
            'barrel_round_count_at_session' => ['nullable', 'integer', 'min:0', 'max:200000'],
            'powder' => ['nullable', 'string', 'max:100'],
            'bullet' => ['nullable', 'string', 'max:100'],
            'brass' => ['nullable', 'string', 'max:100'],
            'primer' => ['nullable', 'string', 'max:100'],
        ]);

        // Scope FK'd records to the owner. If the client hands back a barrel
        // or ammo load id that doesn't belong to them we drop it rather than
        // 403 — safer UX than exploding when a rifle got retired mid-session.
        if (! empty($data['barrel_id'])
            && ! Barrel::whereKey($data['barrel_id'])->where('user_id', auth()->id())->exists()) {
            $data['barrel_id'] = null;
        }
        if (! empty($data['ammo_load_id'])
            && ! AmmoLoad::whereKey($data['ammo_load_id'])->where('user_id', auth()->id())->exists()) {
            $data['ammo_load_id'] = null;
        }

        $data['variable'] = LadderVariable::from($data['variable']);
        $data['temperature_c'] = $data['temperature_c'] !== null && $data['temperature_c'] !== ''
            ? (float) $data['temperature_c']
            : null;

        $this->session->update($data);
        $this->session->refresh();

        session()->flash('metadata-saved', 'Details saved.');
    }

    /**
     * Parse the paste box and materialise steps + shots. Format per spec:
     *   one step per line, first number is the step value, every subsequent
     *   number on that line is a velocity from that string.
     *
     * The parser is deliberately loose: commas, tabs or spaces as separators,
     * lines with fewer than 2 numbers dropped silently, velocities below
     * 200 fps dropped as implausible.
     */
    public function applyPaste(): void
    {
        $this->authorize('update', $this->session);

        if (trim($this->paste) === '') {
            return;
        }

        $lines = preg_split('/\r?\n/', $this->paste);
        $order = $this->session->steps()->max('sort_order') ?? -1;
        $appliedSteps = 0;
        $appliedShots = 0;

        foreach ($lines as $line) {
            preg_match_all('/-?\d+\.?\d*/', $line, $matches);
            $nums = $matches[0] ?? [];
            if (count($nums) < 2) {
                continue;
            }

            $value = round((float) array_shift($nums), 3);
            $velocities = array_values(array_filter(array_map(
                fn ($v) => (float) $v,
                $nums,
            ), fn ($v) => $v >= 200.0));

            if ($velocities === []) {
                continue;
            }

            $step = $this->session->steps()->firstOrNew(['value' => $value]);
            if (! $step->exists) {
                $order++;
                $step->sort_order = $order;
                $step->include_in_fit = true;
                $step->save();
                $appliedSteps++;
            }

            $sequence = $step->shots()->max('sequence') ?? -1;
            foreach ($velocities as $v) {
                $sequence++;
                LadderShot::create([
                    'ladder_step_id' => $step->id,
                    'velocity_fps' => $v,
                    'sequence' => $sequence,
                    'excluded' => false,
                ]);
                $appliedShots++;
            }
        }

        $this->paste = '';
        $this->session->load(['steps.shots']);

        if ($appliedSteps === 0 && $appliedShots === 0) {
            session()->flash('paste-warning', 'Nothing parsed — check that each line has the step value followed by at least one velocity.');
        } else {
            session()->flash('paste-applied', sprintf(
                'Added %d shot%s across %d step%s.',
                $appliedShots,
                $appliedShots === 1 ? '' : 's',
                $appliedSteps,
                $appliedSteps === 1 ? '' : 's',
            ));
        }
    }

    public function toggleStepInFit(int $stepId): void
    {
        $this->authorize('update', $this->session);
        $step = $this->session->steps()->whereKey($stepId)->first();
        if ($step === null) {
            return;
        }
        $step->update(['include_in_fit' => ! $step->include_in_fit]);
        $this->session->load(['steps.shots']);
    }

    public function toggleShotExcluded(int $shotId): void
    {
        $this->authorize('update', $this->session);
        $shot = LadderShot::query()
            ->whereKey($shotId)
            ->whereHas('step', fn ($q) => $q->where('ladder_session_id', $this->session->id))
            ->first();
        if ($shot === null) {
            return;
        }
        $shot->update(['excluded' => ! $shot->excluded]);
        $this->session->load(['steps.shots']);
    }

    public function removeShot(int $shotId): void
    {
        $this->authorize('update', $this->session);
        $shot = LadderShot::query()
            ->whereKey($shotId)
            ->whereHas('step', fn ($q) => $q->where('ladder_session_id', $this->session->id))
            ->first();
        if ($shot === null) {
            return;
        }
        $stepId = $shot->ladder_step_id;
        $shot->delete();

        // Clean up an empty step so the analyser doesn't see phantom rows.
        $step = LadderStep::query()->whereKey($stepId)->withCount('shots')->first();
        if ($step && $step->shots_count === 0) {
            $step->delete();
        }

        $this->session->load(['steps.shots']);
    }

    public function addShot(): void
    {
        $this->authorize('update', $this->session);

        $data = $this->validate([
            'newShotStepId' => ['required', 'integer'],
            'newShotVelocity' => ['required', 'numeric', 'min:200', 'max:6000'],
        ]);

        $step = $this->session->steps()->whereKey($data['newShotStepId'])->first();
        if ($step === null) {
            return;
        }

        $sequence = ($step->shots()->max('sequence') ?? -1) + 1;
        LadderShot::create([
            'ladder_step_id' => $step->id,
            'velocity_fps' => (float) $data['newShotVelocity'],
            'sequence' => $sequence,
            'excluded' => false,
        ]);

        $this->newShotVelocity = '';
        $this->session->load(['steps.shots']);
    }

    public function removeStep(int $stepId): void
    {
        $this->authorize('update', $this->session);
        $step = $this->session->steps()->whereKey($stepId)->first();
        if ($step === null) {
            return;
        }
        $step->delete();
        $this->session->load(['steps.shots']);
    }

    public function addStep(): void
    {
        $this->authorize('update', $this->session);

        $data = $this->validate([
            'newStepValue' => ['required', 'numeric'],
        ]);

        $value = round((float) $data['newStepValue'], 3);
        if ($this->session->steps()->where('value', $value)->exists()) {
            return;
        }

        $order = ($this->session->steps()->max('sort_order') ?? -1) + 1;
        $this->session->steps()->create([
            'value' => $value,
            'include_in_fit' => true,
            'sort_order' => $order,
        ]);

        $this->newStepValue = '';
        $this->session->load(['steps.shots']);
    }

    public function updatedResolvingDelta(): void
    {
        if ($this->resolvingDelta <= 0.0) {
            $this->resolvingDelta = 15.0;
        }
    }

    public function analysis()
    {
        return LadderAnalysis::analyze(
            $this->session->fresh(['steps.shots']) ?? $this->session,
            $this->resolvingDelta,
        );
    }

    public function with(): array
    {
        $result = $this->analysis();
        $variable = $result->variable;

        // Compute chart geometry server-side. All coordinates are in the
        // SVG's 800x360 viewBox — inline the numbers rather than pulling in
        // a charting library.
        $chart = $this->buildChartData($result);

        return [
            'result' => $result,
            'variable' => $variable,
            'chart' => $chart,
            'barrels' => Barrel::forUser(auth()->id())->orderBy('label')->get(),
            'ammoLoads' => AmmoLoad::forUser(auth()->id())->active()->orderBy('nickname')->get(),
        ];
    }

    /**
     * @return array{width: float, height: float, marginLeft: float, marginRight: float, marginTop: float, marginBottom: float, plotX: float, plotY: float, plotWidth: float, plotHeight: float, xTicks: array, yTicks: array, points: array, lineStart: ?array, lineEnd: ?array, residuals: array, hasData: bool}
     */
    protected function buildChartData($result): array
    {
        $width = 800.0;
        $height = 360.0;
        $marginLeft = 60.0;
        $marginRight = 30.0;
        $marginTop = 20.0;
        $marginBottom = 40.0;
        $plotX = $marginLeft;
        $plotY = $marginTop;
        $plotWidth = $width - $marginLeft - $marginRight;
        $plotHeight = $height - $marginTop - $marginBottom;

        $steps = collect($result->steps)->filter(fn ($s) => $s->n > 0)->values();
        if ($steps->isEmpty()) {
            return [
                'width' => $width, 'height' => $height,
                'marginLeft' => $marginLeft, 'marginRight' => $marginRight,
                'marginTop' => $marginTop, 'marginBottom' => $marginBottom,
                'plotX' => $plotX, 'plotY' => $plotY,
                'plotWidth' => $plotWidth, 'plotHeight' => $plotHeight,
                'xTicks' => [], 'yTicks' => [], 'points' => [],
                'lineStart' => null, 'lineEnd' => null,
                'residuals' => [], 'hasData' => false,
            ];
        }

        // x-range: step values, padded a bit
        $xMin = $steps->min('value');
        $xMax = $steps->max('value');
        if ($xMin === $xMax) {
            $xMin -= 0.5;
            $xMax += 0.5;
        }
        $xSpan = $xMax - $xMin;
        $xMin -= $xSpan * 0.05;
        $xMax += $xSpan * 0.05;

        // y-range: from all shots (excluding excluded). Snap min/max out to
        // "nice" round numbers so the axis labels read as 2560, 2580, 2600,…
        // rather than the weird 2563, 2603,… linear-interpolation values.
        $allV = $steps->flatMap(fn ($s) => $s->velocities)->all();
        $yDataMin = min($allV);
        $yDataMax = max($allV);
        if ($yDataMin === $yDataMax) {
            $yDataMin -= 1.0;
            $yDataMax += 1.0;
        }
        $padding = ($yDataMax - $yDataMin) * 0.10;
        [$yMin, $yMax, $yTickValues] = self::niceAxis($yDataMin - $padding, $yDataMax + $padding);

        $mapX = function (float $v) use ($xMin, $xMax, $plotX, $plotWidth) {
            return $plotX + ($v - $xMin) / ($xMax - $xMin) * $plotWidth;
        };
        $mapY = function (float $v) use ($yMin, $yMax, $plotY, $plotHeight) {
            // SVG y grows downwards — flip.
            return $plotY + ($yMax - $v) / ($yMax - $yMin) * $plotHeight;
        };

        // x ticks: one per step value.
        $xTicks = [];
        foreach ($steps as $s) {
            $xTicks[] = [
                'x' => $mapX($s->value),
                'label' => rtrim(rtrim(number_format($s->value, 3, '.', ''), '0'), '.'),
            ];
        }
        $yTicks = [];
        foreach ($yTickValues as $v) {
            $yTicks[] = ['y' => $mapY($v), 'label' => (string) (int) round($v)];
        }

        // Point data — shots and per-step means.
        $points = [];
        foreach ($steps as $s) {
            $shots = [];
            foreach ($s->velocities as $v) {
                $shots[] = ['x' => $mapX($s->value), 'y' => $mapY($v)];
            }
            $errorBar = null;
            if ($s->se !== null) {
                $errorBar = [
                    'x' => $mapX($s->value),
                    'y1' => $mapY($s->mean - $s->se),
                    'y2' => $mapY($s->mean + $s->se),
                ];
            }
            $points[] = [
                'stepId' => $s->stepId,
                'x' => $mapX($s->value),
                'y' => $mapY($s->mean),
                'value' => $s->value,
                'mean' => $s->mean,
                'inFit' => $s->includeInFit,
                'contributesToFit' => $s->contributesToFit,
                'shots' => $shots,
                'errorBar' => $errorBar,
            ];
        }

        // Fitted line — extend across the whole x-range so excluded steps sit
        // beside a trend line, not floating on their own.
        $lineStart = null;
        $lineEnd = null;
        if ($result->trend !== null) {
            $lineStart = [
                'x' => $mapX($xMin + ($xSpan * 0.05)),
                'y' => $mapY($result->trend->predict($xMin + $xSpan * 0.05)),
            ];
            $lineEnd = [
                'x' => $mapX($xMax - ($xSpan * 0.05)),
                'y' => $mapY($result->trend->predict($xMax - $xSpan * 0.05)),
            ];
        }

        // Residual drops — from fitted line down/up to the step mean, with the
        // number labelled inline.
        $residuals = [];
        if ($result->trend !== null) {
            foreach ($result->residuals as $stepId => $residual) {
                $step = $steps->firstWhere('stepId', $stepId);
                if ($step === null) {
                    continue;
                }
                $residuals[] = [
                    'x' => $mapX($step->value),
                    'yLine' => $mapY($result->trend->predict($step->value)),
                    'yPoint' => $mapY($step->mean),
                    'residual' => $residual,
                    'value' => $step->value,
                ];
            }
        }

        return compact(
            'width', 'height',
            'marginLeft', 'marginRight', 'marginTop', 'marginBottom',
            'plotX', 'plotY', 'plotWidth', 'plotHeight',
            'xTicks', 'yTicks', 'points', 'lineStart', 'lineEnd', 'residuals',
        ) + ['hasData' => true];
    }

    /**
     * Snap an axis range to round numbers with a "nice" step size in the
     * {1, 2, 5} × 10^n family, so a raw range like 2562.7–2717.3 renders
     * as 2560–2720 with ticks every 40 rather than the linear-interpolation
     * values 2563 / 2594 / 2625 / 2656 / 2687 / 2717.
     *
     * @return array{0: float, 1: float, 2: list<float>}
     */
    protected static function niceAxis(float $min, float $max, int $desiredTicks = 5): array
    {
        $range = $max - $min;
        if ($range <= 0.0) {
            return [$min, $max, [$min]];
        }

        $rough = $range / $desiredTicks;
        $magnitude = 10.0 ** floor(log10($rough));
        $normalized = $rough / $magnitude;
        $niceNorm = match (true) {
            $normalized < 1.5 => 1.0,
            $normalized < 3.0 => 2.0,
            $normalized < 7.0 => 5.0,
            default => 10.0,
        };
        $step = $niceNorm * $magnitude;

        $niceMin = floor($min / $step) * $step;
        $niceMax = ceil($max / $step) * $step;

        $ticks = [];
        for ($v = $niceMin; $v <= $niceMax + $step / 2; $v += $step) {
            $ticks[] = $v;
        }

        return [$niceMin, $niceMax, $ticks];
    }
} ?>

<div>
    @php
        /** @var \App\Services\Ladder\LadderAnalysisResult $result */
        $verdictClasses = match ($result->verdict->case) {
            \App\Services\Ladder\DTO\LadderVerdict::NODES_FOUND => 'border-emerald-200 bg-emerald-50 text-emerald-900',
            \App\Services\Ladder\DTO\LadderVerdict::NO_NODE_SUPPORTED => 'border-amber-200 bg-amber-50 text-amber-900',
            default => 'border-stone-200 bg-stone-50 text-stone-800',
        };
    @endphp

    <style>
        @media print {
            body { background: white !important; }
            .ladder-print-hide, aside, header, nav, footer { display: none !important; }
            .ladder-print-only { display: block !important; }
            /* Force <details> panels open on print so glossaries appear on paper. */
            details > *:not(summary) { display: block !important; }
            details > summary { list-style: none; }
            details > summary::-webkit-details-marker { display: none; }
        }
        .ladder-hint {
            border-bottom: 1px dotted #a8a29e;
            cursor: help;
        }
    </style>

    <div class="space-y-6">
        <div class="flex items-center justify-between ladder-print-hide">
            <div>
                <a href="{{ route('ladder-sessions.index') }}" class="text-sm text-emerald-700 font-medium hover:text-emerald-800">&larr; All ladders</a>
                <h1 class="mt-1 font-heading text-3xl font-bold text-stone-900 tracking-tight">{{ $session->name }}</h1>
                <p class="mt-1 text-sm text-stone-500">
                    {{ $variable->label() }} ladder &middot; fired {{ optional($session->fired_on)->format('j M Y') }}
                </p>
            </div>
            <div class="flex items-center gap-3">
                <a href="{{ route('ladder-sessions.export.csv', $session) }}"
                   class="inline-flex items-center gap-1.5 rounded-lg border border-stone-300 bg-white px-3 py-2 text-xs font-semibold text-stone-700 hover:bg-stone-50 transition">
                    Export CSV
                </a>
                <button type="button" onclick="window.print()"
                        class="inline-flex items-center gap-1.5 rounded-lg border border-stone-300 bg-white px-3 py-2 text-xs font-semibold text-stone-700 hover:bg-stone-50 transition">
                    Print
                </button>
                <form method="POST" action="{{ route('ladder-sessions.destroy', $session) }}"
                      onsubmit="return confirm('Delete this ladder session?');">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="rounded-lg border border-red-200 bg-white px-3 py-2 text-xs font-semibold text-red-700 hover:bg-red-50 transition">
                        Delete
                    </button>
                </form>
            </div>
        </div>

        {{-- Analysis pane --}}
        <section class="rounded-xl border {{ $verdictClasses }} p-5">
            <p class="text-sm font-semibold uppercase tracking-wider">Verdict</p>
            <p class="mt-1 text-sm">{{ $result->verdict->text }}</p>
            @if ($result->sdComparison !== null)
                <p class="mt-3 text-sm text-stone-700">{{ $result->sdComparison->text }}</p>
            @endif
            @php $conditioningFlags = collect($result->pairs)->filter(fn($p) => $p->exceedsFittedSlope); @endphp
            @if ($conditioningFlags->isNotEmpty())
                <ul class="mt-3 space-y-1 text-sm text-stone-700 list-disc list-inside">
                    @foreach ($conditioningFlags as $p)
                        <li>
                            Step {{ number_format($p->fromValue, 2) }} → {{ number_format($p->toValue, 2) }}:
                            step slope {{ number_format($p->stepSlope, 1) }} {{ $variable->slopeUnit() }} is larger than the increment can account for. If the ladder was fired in ascending order this most likely marks where the barrel finished conditioning.
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>

        <section class="grid grid-cols-2 sm:grid-cols-4 gap-3">
            <div class="rounded-xl border border-stone-200 bg-white p-4" title="Velocity change per unit of the variable. OLS line through the in-fit step means, weighted by SE. Steps with n<2 can't contribute.">
                <p class="text-[11px] font-semibold uppercase tracking-wider text-stone-500 ladder-hint">Fitted slope</p>
                <p class="mt-1 text-lg font-bold text-stone-900">
                    {{ $result->trend !== null ? number_format($result->trend->slope, 2).' '.$variable->slopeUnit() : '—' }}
                </p>
                <p class="mt-1 text-xs text-stone-500">
                    {{ $result->trend !== null ? $result->trend->stepsUsed.' steps in fit' : 'need ≥2 steps' }}
                </p>
            </div>
            <div class="rounded-xl border border-stone-200 bg-white p-4" title="Best estimate of within-step shot-to-shot spread, pooled across every step with n≥2. What the ammo does once charge variation is taken out.">
                <p class="text-[11px] font-semibold uppercase tracking-wider text-stone-500 ladder-hint">Pooled SD</p>
                <p class="mt-1 text-lg font-bold text-stone-900">
                    {{ $result->pooledSd !== null ? number_format($result->pooledSd, 2).' fps' : '—' }}
                </p>
                <p class="mt-1 text-xs text-stone-500">
                    {{ $result->pooledDf !== null ? 'df '.$result->pooledDf : 'no repeated steps' }}
                </p>
            </div>
            <div class="rounded-xl border border-stone-200 bg-white p-4" title="Of the adjacent step pairs, how many are statistically distinguishable at p<0.05 by Welch's t on the means.">
                <p class="text-[11px] font-semibold uppercase tracking-wider text-stone-500 ladder-hint">Pairs separating</p>
                @php
                    $testable = collect($result->pairs)->count();
                    $separating = collect($result->pairs)->filter(fn($p) => $p->classification === \App\Enums\PairSeparation::Separates)->count();
                @endphp
                <p class="mt-1 text-lg font-bold text-stone-900">{{ $separating }} of {{ $testable }}</p>
                <p class="mt-1 text-xs text-stone-500">adjacent Welch's t, p&lt;0.05</p>
            </div>
            <div class="rounded-xl border border-stone-200 bg-white p-4" title="Biggest departure from the fitted trend line among steps not contributing to the fit. Sign preserved.">
                <p class="text-[11px] font-semibold uppercase tracking-wider text-stone-500 ladder-hint">Largest residual</p>
                @php $largest = $result->largestResidual(); @endphp
                <p class="mt-1 text-lg font-bold text-stone-900">
                    {{ $largest !== null ? ($largest >= 0 ? '+' : '').number_format($largest, 2).' fps' : '—' }}
                </p>
                <p class="mt-1 text-xs text-stone-500">against fitted trend</p>
            </div>
        </section>

        {{-- Glossary. Collapsed by default; opens with a click. Prints expanded
             so a paper copy handed to another shooter is self-explanatory. --}}
        <details class="rounded-xl border border-stone-200 bg-white">
            <summary class="cursor-pointer select-none px-4 py-3 text-sm font-semibold text-stone-900 hover:bg-stone-50 rounded-xl">
                How to read this analysis
                <span class="ml-1 text-xs font-normal text-stone-500">— every metric on this page, in plain terms</span>
            </summary>
            <div class="border-t border-stone-100 px-4 py-4 space-y-5 text-sm text-stone-700">

                <div>
                    <p class="font-semibold text-stone-900 text-xs uppercase tracking-wider mb-2">Summary cards</p>
                    <dl class="space-y-2">
                        <div class="grid sm:grid-cols-[160px_1fr] gap-2">
                            <dt class="font-semibold text-stone-800">Fitted slope</dt>
                            <dd>Velocity gained per unit of {{ strtolower($variable->label()) }} ({{ $variable->slopeUnit() }}). OLS line through the step means you've marked "in fit," weighted by each step's standard error. Steps with n&nbsp;&lt;&nbsp;2 can't contribute regardless of the checkbox — you can't estimate a mean with uncertainty from one shot.</dd>
                        </div>
                        <div class="grid sm:grid-cols-[160px_1fr] gap-2">
                            <dt class="font-semibold text-stone-800">Pooled SD</dt>
                            <dd>Best estimate of shot-to-shot spread, pooled across every step with n&nbsp;≥&nbsp;2. This is what the ammo does once you take charge variation out of the picture. The "df" underneath is the total degrees of freedom that went into the pool.</dd>
                        </div>
                        <div class="grid sm:grid-cols-[160px_1fr] gap-2">
                            <dt class="font-semibold text-stone-800">Pairs separating</dt>
                            <dd>Of the adjacent step pairs, how many are statistically distinguishable at p&nbsp;&lt;&nbsp;0.05 by Welch's t on the means. "0 of 6" means the string sizes are too small to tell any two adjacent charges apart with confidence.</dd>
                        </div>
                        <div class="grid sm:grid-cols-[160px_1fr] gap-2">
                            <dt class="font-semibold text-stone-800">Largest residual</dt>
                            <dd>Biggest departure from the fitted trend line among steps that don't contribute to the fit. Sign is preserved: <span class="font-mono">+38</span> means the point sat 38 fps above the line, <span class="font-mono">−12</span> means below.</dd>
                        </div>
                    </dl>
                </div>

                <div>
                    <p class="font-semibold text-stone-900 text-xs uppercase tracking-wider mb-2">Chart</p>
                    <ul class="space-y-1.5 list-disc list-inside">
                        <li><span class="font-semibold">Solid green circle</span> — step mean, in the fit.</li>
                        <li><span class="font-semibold">Solid grey circle</span> — step mean, NOT in the fit (either you toggled it off or n&nbsp;&lt;&nbsp;2).</li>
                        <li><span class="font-semibold">Vertical bar with T-caps</span> — ±1 standard error of the mean. About a 68% range on where the true mean sits.</li>
                        <li><span class="font-semibold">Faint dots</span> — the individual shot velocities. Tight cluster = tight string.</li>
                        <li><span class="font-semibold">Dashed green line</span> — the fitted trend through the in-fit means.</li>
                        <li><span class="font-semibold">Dashed grey drop-line with number</span> — residual: how far a non-fit point sits above (+) or below (−) the trend. This is where the ladder is "not doing what the trend says it should."</li>
                    </ul>
                </div>

                <div>
                    <p class="font-semibold text-stone-900 text-xs uppercase tracking-wider mb-2">Per-step statistics table</p>
                    <dl class="space-y-2">
                        <div class="grid sm:grid-cols-[160px_1fr] gap-2">
                            <dt class="font-semibold text-stone-800">n</dt>
                            <dd>Number of shots on that step (excluded shots don't count).</dd>
                        </div>
                        <div class="grid sm:grid-cols-[160px_1fr] gap-2">
                            <dt class="font-semibold text-stone-800">Mean</dt>
                            <dd>Arithmetic average of the shot velocities on that step.</dd>
                        </div>
                        <div class="grid sm:grid-cols-[160px_1fr] gap-2">
                            <dt class="font-semibold text-stone-800">SD</dt>
                            <dd>Sample standard deviation of the shots on that step. Blank when n&nbsp;&lt;&nbsp;2 — SD is undefined for a single shot.</dd>
                        </div>
                        <div class="grid sm:grid-cols-[160px_1fr] gap-2">
                            <dt class="font-semibold text-stone-800">SD 90% CI</dt>
                            <dd>90% confidence interval for the <em>true</em> SD of that load, given how few shots you fired. Built from the chi-square distribution: a 3-shot sample SD of 6 fps is compatible with a real SD of 3.5–29 fps. Wide bands are the honest truth about tiny samples, not a bug.</dd>
                        </div>
                        <div class="grid sm:grid-cols-[160px_1fr] gap-2">
                            <dt class="font-semibold text-stone-800">ES</dt>
                            <dd>Extreme spread: max velocity minus min velocity. Popular but statistically weak — ES grows with n even when the underlying spread hasn't. Use SD as the real spread number and treat ES as a talking point.</dd>
                        </div>
                        <div class="grid sm:grid-cols-[160px_1fr] gap-2">
                            <dt class="font-semibold text-stone-800">Δ from prev</dt>
                            <dd>How far this step's mean sits above (+) or below (−) the previous step's mean. Reads the ladder linearly rather than statistically.</dd>
                        </div>
                        <div class="grid sm:grid-cols-[160px_1fr] gap-2">
                            <dt class="font-semibold text-stone-800">Residual</dt>
                            <dd>Shown alongside the step value for non-fit steps: the vertical distance from this step's mean to the fitted trend. Same value plotted as a drop-line on the chart.</dd>
                        </div>
                        <div class="grid sm:grid-cols-[160px_1fr] gap-2">
                            <dt class="font-semibold text-stone-800">In fit</dt>
                            <dd>Toggle to include or exclude this step from the OLS fit. Excluded steps still show their point and residual on the chart — they're just not shaping the trend line.</dd>
                        </div>
                    </dl>
                </div>

                @if (!empty($result->pairs))
                    <div>
                        <p class="font-semibold text-stone-900 text-xs uppercase tracking-wider mb-2">Adjacent comparisons (Welch's t)</p>
                        <p class="text-xs text-stone-500 mb-2">Each row asks: given the small strings we fired, is the difference between these two adjacent charges believable, or is it noise?</p>
                        <dl class="space-y-2">
                            <div class="grid sm:grid-cols-[160px_1fr] gap-2">
                                <dt class="font-semibold text-stone-800">d (fps)</dt>
                                <dd>mean(step B) − mean(step A). Positive = charge B is faster.</dd>
                            </div>
                            <div class="grid sm:grid-cols-[160px_1fr] gap-2">
                                <dt class="font-semibold text-stone-800">SE(d)</dt>
                                <dd>Standard error of the difference, using each step's own SD/n. This is where small-sample humility lives — SE(d) is often larger than d itself in a 3-shot ladder.</dd>
                            </div>
                            <div class="grid sm:grid-cols-[160px_1fr] gap-2">
                                <dt class="font-semibold text-stone-800">t</dt>
                                <dd>Welch's t statistic = d ÷ SE(d). Large |t| = the difference is large relative to the noise.</dd>
                            </div>
                            <div class="grid sm:grid-cols-[160px_1fr] gap-2">
                                <dt class="font-semibold text-stone-800">df</dt>
                                <dd>Welch–Satterthwaite degrees of freedom. Usually fractional and near n₁+n₂−2 but drops sharply when one step is much noisier than the other.</dd>
                            </div>
                            <div class="grid sm:grid-cols-[160px_1fr] gap-2">
                                <dt class="font-semibold text-stone-800">p</dt>
                                <dd>Two-tailed p-value from Student's t at the df above. Smaller = more confident the difference isn't noise. This tool uses 0.05 as the "separates" threshold and 0.15 as "marginal."</dd>
                            </div>
                            <div class="grid sm:grid-cols-[160px_1fr] gap-2">
                                <dt class="font-semibold text-stone-800">Step slope</dt>
                                <dd>d divided by the increment size, in {{ $variable->slopeUnit() }}. If a single pair's step slope is more than about 3× the overall fitted slope it flags in the verdict — usually barrel conditioning if the ladder was fired in ascending order.</dd>
                            </div>
                            <div class="grid sm:grid-cols-[160px_1fr] gap-2">
                                <dt class="font-semibold text-stone-800">Result</dt>
                                <dd><span class="text-emerald-700 font-semibold">Separates</span> = p&nbsp;&lt;&nbsp;0.05. <span class="text-amber-700 font-semibold">Marginal</span> = 0.05&nbsp;≤&nbsp;p&nbsp;&lt;&nbsp;0.15. <span class="text-stone-500">Indistinguishable</span> = p&nbsp;≥&nbsp;0.15.</dd>
                            </div>
                        </dl>
                    </div>
                @endif

                <div>
                    <p class="font-semibold text-stone-900 text-xs uppercase tracking-wider mb-2">Rounds required</p>
                    <p>Given the pooled SD, how many shots per step you'd need to reliably resolve the "Resolve&nbsp;d" figure as a real difference (80% power at α&nbsp;=&nbsp;0.05, Welch's t on equal-n strings). Change the field to see the trade-off: asking to resolve 5&nbsp;fps demands a lot of shots, resolving 40&nbsp;fps is easy.</p>
                </div>

                <div>
                    <p class="font-semibold text-stone-900 text-xs uppercase tracking-wider mb-2">Verdict &amp; conditioning flags</p>
                    <ul class="space-y-1.5 list-disc list-inside">
                        <li><span class="font-semibold text-emerald-800">Nodes found</span> — at least one adjacent pair separates and there's a plausible flat spot in the trend.</li>
                        <li><span class="font-semibold text-amber-800">No node supported</span> — the trend is linear enough (or the noise large enough) that no plateau is defensible from this data.</li>
                        <li><span class="font-semibold text-stone-700">Analysis not viable</span> — too few steps or too few shots to say anything at all.</li>
                        <li><span class="font-semibold">Conditioning flag</span> — a pair whose step slope is more than ≈3× the fitted slope. Fired ascending, this typically marks where the barrel finished conditioning: fresh copper laid down, velocity jumped, everything after is on a new baseline.</li>
                    </ul>
                </div>

            </div>
        </details>

        {{-- Chart --}}
        <section class="rounded-xl border border-stone-200 bg-white p-4">
            <div class="flex items-center justify-between mb-3">
                <div>
                    <p class="text-sm font-semibold text-stone-900">Mean velocity vs {{ $variable->label() }}</p>
                    <p class="text-xs text-stone-500">Points = step means, bars = ±1 SE, faint dots = individual shots.</p>
                </div>
                <div class="flex items-center gap-4 text-xs text-stone-600">
                    <span class="inline-flex items-center gap-1"><span class="inline-block h-2.5 w-2.5 rounded-full bg-emerald-700"></span>In fit</span>
                    <span class="inline-flex items-center gap-1"><span class="inline-block h-2.5 w-2.5 rounded-full bg-stone-400"></span>Not in fit</span>
                    <span class="inline-flex items-center gap-1">
                        <svg width="18" height="6" class="shrink-0" aria-hidden="true"><line x1="0" y1="3" x2="18" y2="3" stroke="#047857" stroke-width="2" stroke-dasharray="4 3" /></svg>
                        Fitted trend
                    </span>
                </div>
            </div>
            @if ($chart['hasData'])
                <svg viewBox="0 0 {{ $chart['width'] }} {{ $chart['height'] }}" class="w-full">
                    {{-- Frame --}}
                    <rect x="{{ $chart['plotX'] }}" y="{{ $chart['plotY'] }}"
                          width="{{ $chart['plotWidth'] }}" height="{{ $chart['plotHeight'] }}"
                          fill="#fafaf9" stroke="#e7e5e4" />

                    {{-- Y grid + labels --}}
                    @foreach ($chart['yTicks'] as $tick)
                        <line x1="{{ $chart['plotX'] }}" y1="{{ $tick['y'] }}"
                              x2="{{ $chart['plotX'] + $chart['plotWidth'] }}" y2="{{ $tick['y'] }}"
                              stroke="#e7e5e4" stroke-dasharray="2 3" />
                        <text x="{{ $chart['plotX'] - 8 }}" y="{{ $tick['y'] + 4 }}"
                              font-size="11" fill="#78716c" text-anchor="end">{{ $tick['label'] }}</text>
                    @endforeach

                    {{-- X grid (subtle, one line per step value) + tick labels --}}
                    @foreach ($chart['xTicks'] as $tick)
                        <line x1="{{ $tick['x'] }}" y1="{{ $chart['plotY'] }}"
                              x2="{{ $tick['x'] }}" y2="{{ $chart['plotY'] + $chart['plotHeight'] }}"
                              stroke="#e7e5e4" stroke-dasharray="2 3" />
                        <line x1="{{ $tick['x'] }}" y1="{{ $chart['plotY'] + $chart['plotHeight'] }}"
                              x2="{{ $tick['x'] }}" y2="{{ $chart['plotY'] + $chart['plotHeight'] + 4 }}"
                              stroke="#78716c" />
                        <text x="{{ $tick['x'] }}" y="{{ $chart['plotY'] + $chart['plotHeight'] + 18 }}"
                              font-size="11" fill="#78716c" text-anchor="middle">{{ $tick['label'] }}</text>
                    @endforeach

                    {{-- Fitted line (dashed) --}}
                    @if ($chart['lineStart'] !== null)
                        <line x1="{{ $chart['lineStart']['x'] }}" y1="{{ $chart['lineStart']['y'] }}"
                              x2="{{ $chart['lineEnd']['x'] }}" y2="{{ $chart['lineEnd']['y'] }}"
                              stroke="#047857" stroke-width="2" stroke-dasharray="5 4" />
                    @endif

                    {{-- Residual drop lines --}}
                    @foreach ($chart['residuals'] as $r)
                        <line x1="{{ $r['x'] }}" y1="{{ $r['yLine'] }}"
                              x2="{{ $r['x'] }}" y2="{{ $r['yPoint'] }}"
                              stroke="#a3a3a3" stroke-width="1" stroke-dasharray="2 2" />
                        <text x="{{ $r['x'] + 6 }}" y="{{ ($r['yLine'] + $r['yPoint']) / 2 }}"
                              font-size="10" fill="#57534e">
                            {{ ($r['residual'] >= 0 ? '+' : '').number_format($r['residual'], 1) }}
                        </text>
                    @endforeach

                    {{-- Points --}}
                    @foreach ($chart['points'] as $pt)
                        {{-- Faint shot dots behind --}}
                        @foreach ($pt['shots'] as $s)
                            <circle cx="{{ $s['x'] }}" cy="{{ $s['y'] }}" r="2.5"
                                    fill="#d6d3d1" opacity="0.7" />
                        @endforeach
                        {{-- SE bar --}}
                        @if ($pt['errorBar'] !== null)
                            <line x1="{{ $pt['errorBar']['x'] }}" y1="{{ $pt['errorBar']['y1'] }}"
                                  x2="{{ $pt['errorBar']['x'] }}" y2="{{ $pt['errorBar']['y2'] }}"
                                  stroke="{{ $pt['contributesToFit'] ? '#047857' : '#a8a29e' }}" stroke-width="2" />
                            <line x1="{{ $pt['errorBar']['x'] - 5 }}" y1="{{ $pt['errorBar']['y1'] }}"
                                  x2="{{ $pt['errorBar']['x'] + 5 }}" y2="{{ $pt['errorBar']['y1'] }}"
                                  stroke="{{ $pt['contributesToFit'] ? '#047857' : '#a8a29e' }}" stroke-width="2" />
                            <line x1="{{ $pt['errorBar']['x'] - 5 }}" y1="{{ $pt['errorBar']['y2'] }}"
                                  x2="{{ $pt['errorBar']['x'] + 5 }}" y2="{{ $pt['errorBar']['y2'] }}"
                                  stroke="{{ $pt['contributesToFit'] ? '#047857' : '#a8a29e' }}" stroke-width="2" />
                        @endif
                        {{-- Mean marker --}}
                        <circle cx="{{ $pt['x'] }}" cy="{{ $pt['y'] }}" r="5"
                                fill="{{ $pt['contributesToFit'] ? '#047857' : '#a8a29e' }}" />
                    @endforeach

                    <text x="{{ $chart['width'] / 2 }}" y="{{ $chart['height'] - 6 }}"
                          font-size="12" fill="#57534e" text-anchor="middle">{{ $variable->axisLabel() }}</text>
                    <text x="14" y="{{ $chart['plotY'] + $chart['plotHeight'] / 2 }}"
                          font-size="12" fill="#57534e"
                          transform="rotate(-90 14 {{ $chart['plotY'] + $chart['plotHeight'] / 2 }})"
                          text-anchor="middle">Velocity (fps)</text>
                </svg>
            @else
                <p class="text-center text-sm text-stone-400 py-10">Enter velocities to see the chart.</p>
            @endif
        </section>

        {{-- Per-step table --}}
        <section class="rounded-xl border border-stone-200 bg-white overflow-hidden">
            <div class="px-4 py-3 border-b border-stone-100 flex items-center justify-between">
                <div>
                    <p class="text-sm font-semibold text-stone-900">Per-step statistics</p>
                    <p class="text-xs text-stone-500">Toggle a step in or out of the fit to see the residual against trend.</p>
                </div>
                <div class="flex items-center gap-2 ladder-print-hide">
                    <label class="text-xs font-medium text-stone-600">Resolve d (fps)</label>
                    <input type="number" wire:model.live.debounce.500ms="resolvingDelta" min="1" step="0.5"
                           class="w-20 rounded-lg border border-stone-300 text-xs py-1.5 px-2 focus:ring-emerald-500 focus:border-emerald-500">
                </div>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-stone-100 text-sm">
                    <thead class="bg-stone-50 text-xs uppercase tracking-wider text-stone-500">
                        <tr>
                            <th class="px-3 py-2 text-left">{{ $variable->axisLabel() }}</th>
                            <th class="px-3 py-2 text-right" title="Number of shots on this step (excluded shots don't count).">
                                <span class="ladder-hint">n</span>
                            </th>
                            <th class="px-3 py-2 text-right" title="Arithmetic average of the shot velocities on this step.">
                                <span class="ladder-hint">Mean</span>
                            </th>
                            <th class="px-3 py-2 text-right" title="Sample standard deviation of the shots on this step. Undefined for n<2.">
                                <span class="ladder-hint">SD</span>
                            </th>
                            <th class="px-3 py-2 text-right" title="90% confidence interval for the TRUE SD of this load, given how few shots were fired. Chi-square construction. Wide bands are the honest truth about small samples.">
                                <span class="ladder-hint">SD 90% CI</span>
                            </th>
                            <th class="px-3 py-2 text-right" title="Extreme spread: max − min. Popular but weak — ES grows with n even when the underlying spread hasn't. Prefer SD.">
                                <span class="ladder-hint">ES</span>
                            </th>
                            <th class="px-3 py-2 text-right" title="How far this step's mean sits above (+) or below (−) the previous step's mean. Linear read of the ladder, no statistics.">
                                <span class="ladder-hint">Δ from prev</span>
                            </th>
                            <th class="px-3 py-2 text-left">Shots</th>
                            <th class="px-3 py-2 text-center ladder-print-hide" title="Include or exclude this step from the OLS trend fit. Excluded steps still show their point and residual on the chart.">
                                <span class="ladder-hint">In fit</span>
                            </th>
                            <th class="px-3 py-2 ladder-print-hide"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-stone-100">
                        @php $prevMean = null; @endphp
                        @foreach ($result->steps as $step)
                            @php
                                $delta = $prevMean === null ? null : $step->mean - $prevMean;
                                $prevMean = $step->n > 0 ? $step->mean : $prevMean;
                                $residual = $result->residuals[$step->stepId] ?? null;
                            @endphp
                            <tr class="{{ $step->contributesToFit ? 'bg-white' : 'bg-stone-50/40' }}">
                                <td class="px-3 py-2 font-semibold text-stone-900">
                                    {{ rtrim(rtrim(number_format($step->value, 3, '.', ''), '0'), '.') }}
                                    @if ($residual !== null)
                                        <span class="ml-2 text-xs font-medium text-stone-500">
                                            residual {{ ($residual >= 0 ? '+' : '').number_format($residual, 2) }}
                                        </span>
                                    @endif
                                </td>
                                <td class="px-3 py-2 text-right tabular-nums text-stone-700">{{ $step->n }}</td>
                                <td class="px-3 py-2 text-right tabular-nums text-stone-700">
                                    {{ $step->n > 0 ? number_format($step->mean, 1) : '—' }}
                                </td>
                                <td class="px-3 py-2 text-right tabular-nums text-stone-700">
                                    {{ $step->sd !== null ? number_format($step->sd, 2) : '—' }}
                                </td>
                                <td class="px-3 py-2 text-right tabular-nums text-stone-500">
                                    @if ($step->sdCiLower !== null)
                                        {{ number_format($step->sdCiLower, 2) }} – {{ number_format($step->sdCiUpper, 2) }}
                                    @else
                                        —
                                    @endif
                                </td>
                                <td class="px-3 py-2 text-right tabular-nums text-stone-700">
                                    {{ $step->es !== null ? number_format($step->es, 1) : '—' }}
                                </td>
                                <td class="px-3 py-2 text-right tabular-nums text-stone-700">
                                    {{ $delta !== null ? ($delta >= 0 ? '+' : '').number_format($delta, 1) : '—' }}
                                </td>
                                <td class="px-3 py-2 text-xs text-stone-600">
                                    <div class="flex flex-wrap gap-1">
                                        @foreach ($step->velocities as $v)
                                            <span class="rounded bg-stone-100 px-1.5 py-0.5 tabular-nums">{{ number_format($v, 1) }}</span>
                                        @endforeach
                                        {{-- Excluded shots so the shooter can still see what they dropped. --}}
                                        @php $rawShots = $session->steps->firstWhere('id', $step->stepId)?->shots ?? collect(); @endphp
                                        @foreach ($rawShots->where('excluded', true) as $exc)
                                            <button type="button" wire:click="toggleShotExcluded({{ $exc->id }})" class="rounded bg-red-50 text-red-500 px-1.5 py-0.5 tabular-nums line-through ladder-print-hide" title="Re-include">
                                                {{ number_format((float) $exc->velocity_fps, 1) }}
                                            </button>
                                        @endforeach
                                    </div>
                                    <div class="mt-1 flex flex-wrap gap-1 ladder-print-hide">
                                        @foreach ($rawShots->where('excluded', false) as $shot)
                                            <button type="button" wire:click="toggleShotExcluded({{ $shot->id }})" class="text-[10px] text-stone-400 hover:text-red-600" title="Exclude this shot">
                                                × {{ number_format((float) $shot->velocity_fps, 1) }}
                                            </button>
                                        @endforeach
                                    </div>
                                </td>
                                <td class="px-3 py-2 text-center ladder-print-hide">
                                    <input type="checkbox"
                                           wire:click="toggleStepInFit({{ $step->stepId }})"
                                           @checked($step->includeInFit)
                                           class="rounded border-stone-300 text-emerald-600 focus:ring-emerald-500">
                                </td>
                                <td class="px-3 py-2 text-right ladder-print-hide">
                                    <button type="button" wire:click="removeStep({{ $step->stepId }})"
                                            class="text-xs text-stone-400 hover:text-red-600"
                                            onclick="return confirm('Remove this step and all its shots?');">Remove</button>
                                </td>
                            </tr>
                        @endforeach
                        @if (empty($result->steps))
                            <tr><td colspan="10" class="px-3 py-6 text-center text-sm text-stone-400">No steps yet.</td></tr>
                        @endif
                    </tbody>
                </table>
            </div>
        </section>

        {{-- Pair table --}}
        @if (!empty($result->pairs))
            <section class="rounded-xl border border-stone-200 bg-white overflow-hidden">
                <div class="px-4 py-3 border-b border-stone-100">
                    <p class="text-sm font-semibold text-stone-900">Adjacent comparisons (Welch's t)</p>
                </div>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-stone-100 text-sm">
                        <thead class="bg-stone-50 text-xs uppercase tracking-wider text-stone-500">
                            <tr>
                                <th class="px-3 py-2 text-left">Pair</th>
                                <th class="px-3 py-2 text-right" title="Difference of means: step B mean − step A mean. Positive means charge B is faster.">
                                    <span class="ladder-hint">d (fps)</span>
                                </th>
                                <th class="px-3 py-2 text-right" title="Standard error of the difference, using each step's own SD/n. Often larger than d itself in 3-shot strings.">
                                    <span class="ladder-hint">SE(d)</span>
                                </th>
                                <th class="px-3 py-2 text-right" title="Welch's t statistic: d ÷ SE(d). Large |t| means the difference is large relative to the noise.">
                                    <span class="ladder-hint">t</span>
                                </th>
                                <th class="px-3 py-2 text-right" title="Welch–Satterthwaite degrees of freedom. Usually fractional; drops sharply when one step is much noisier than the other.">
                                    <span class="ladder-hint">df</span>
                                </th>
                                <th class="px-3 py-2 text-right" title="Two-tailed p-value from Student's t. Smaller = more confident the difference isn't noise. This tool uses 0.05 for 'separates' and 0.15 for 'marginal'.">
                                    <span class="ladder-hint">p</span>
                                </th>
                                <th class="px-3 py-2 text-right" title="d divided by the increment size. Flags in the verdict if any single pair's slope exceeds ~3× the overall fitted slope — typically barrel conditioning if fired ascending.">
                                    <span class="ladder-hint">Step slope</span>
                                </th>
                                <th class="px-3 py-2 text-left" title="Separates = p<0.05. Marginal = 0.05≤p<0.15. Indistinguishable = p≥0.15.">
                                    <span class="ladder-hint">Result</span>
                                </th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-stone-100">
                            @foreach ($result->pairs as $pair)
                                @php
                                    $rowColour = match ($pair->classification) {
                                        \App\Enums\PairSeparation::Separates => 'text-emerald-700 font-semibold',
                                        \App\Enums\PairSeparation::Marginal => 'text-amber-700 font-semibold',
                                        default => 'text-stone-500',
                                    };
                                @endphp
                                <tr>
                                    <td class="px-3 py-2 text-stone-800">
                                        {{ rtrim(rtrim(number_format($pair->fromValue, 3, '.', ''), '0'), '.') }}
                                        →
                                        {{ rtrim(rtrim(number_format($pair->toValue, 3, '.', ''), '0'), '.') }}
                                    </td>
                                    <td class="px-3 py-2 text-right tabular-nums text-stone-700">{{ number_format($pair->d, 2) }}</td>
                                    <td class="px-3 py-2 text-right tabular-nums text-stone-700">{{ number_format($pair->seD, 2) }}</td>
                                    <td class="px-3 py-2 text-right tabular-nums text-stone-700">{{ number_format($pair->t, 2) }}</td>
                                    <td class="px-3 py-2 text-right tabular-nums text-stone-700">{{ number_format($pair->df, 1) }}</td>
                                    <td class="px-3 py-2 text-right tabular-nums text-stone-700">{{ number_format($pair->p, 3) }}</td>
                                    <td class="px-3 py-2 text-right tabular-nums text-stone-700">{{ number_format($pair->stepSlope, 1) }}</td>
                                    <td class="px-3 py-2 {{ $rowColour }}">{{ $pair->classification->label() }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </section>
        @endif

        {{-- Rounds required --}}
        @if ($result->pooledSd !== null)
            <section class="rounded-xl border border-stone-200 bg-white p-4">
                <p class="text-sm text-stone-700">
                    Given the pooled SD of {{ number_format($result->pooledSd, 2) }} fps,
                    resolving a {{ number_format($result->resolvingDelta, 1) }} fps difference between adjacent charges
                    with 80% power at α = 0.05 requires
                    <span class="font-bold text-stone-900">{{ $result->roundsRequired }} shots per step</span>.
                </p>
            </section>
        @endif

        {{-- Editor pane --}}
        <div class="grid gap-6 md:grid-cols-2 ladder-print-hide">
            {{-- Paste box --}}
            <section class="rounded-xl border border-stone-200 bg-white p-5 space-y-3">
                <div>
                    <p class="text-sm font-semibold text-stone-900">Paste a ladder</p>
                    <p class="text-xs text-stone-500">One step per line, first number is the step value, everything after is a velocity.</p>
                </div>
                <textarea wire:model="paste" rows="8" placeholder="40.4  2618.7  2608.8  2607.1&#10;40.6  2611.6  2606.0  2634.7"
                          class="w-full rounded-lg border border-stone-300 font-mono text-xs py-2.5 px-3 focus:ring-emerald-500 focus:border-emerald-500"></textarea>
                @if (session('paste-warning'))
                    <p class="text-xs text-amber-700">{{ session('paste-warning') }}</p>
                @endif
                @if (session('paste-applied'))
                    <p class="text-xs text-emerald-700">{{ session('paste-applied') }}</p>
                @endif
                <button type="button" wire:click="applyPaste"
                        class="rounded-lg bg-emerald-700 px-4 py-2 text-xs font-semibold text-white hover:bg-emerald-800 transition">
                    Apply
                </button>
            </section>

            {{-- Row-by-row editor --}}
            <section class="rounded-xl border border-stone-200 bg-white p-5 space-y-4">
                <div>
                    <p class="text-sm font-semibold text-stone-900">Add step or shot</p>
                    <p class="text-xs text-stone-500">For fixing single readings after paste.</p>
                </div>
                <div class="space-y-2">
                    <label class="text-xs font-medium text-stone-500">New step value ({{ $variable->unit() }})</label>
                    <div class="flex items-center gap-2">
                        <input type="number" step="0.001" wire:model="newStepValue"
                               placeholder="e.g. 40.2"
                               class="flex-1 rounded-lg border border-stone-300 text-sm py-2 px-3 focus:ring-emerald-500 focus:border-emerald-500">
                        <button type="button" wire:click="addStep"
                                class="rounded-lg bg-stone-800 px-3 py-2 text-xs font-semibold text-white hover:bg-stone-900">Add step</button>
                    </div>
                    @error('newStepValue') <p class="text-xs text-red-600">{{ $message }}</p> @enderror
                </div>
                <div class="space-y-2">
                    <label class="text-xs font-medium text-stone-500">Add shot to step</label>
                    <div class="grid grid-cols-3 gap-2">
                        <select wire:model="newShotStepId" class="col-span-2 rounded-lg border border-stone-300 text-sm py-2 px-3 focus:ring-emerald-500 focus:border-emerald-500">
                            <option value="">Pick a step…</option>
                            @foreach ($session->steps->sortBy(['sort_order', 'value']) as $step)
                                <option value="{{ $step->id }}">{{ rtrim(rtrim(number_format((float) $step->value, 3, '.', ''), '0'), '.') }} {{ $variable->unit() }}</option>
                            @endforeach
                        </select>
                        <input type="number" step="0.1" wire:model="newShotVelocity"
                               placeholder="fps"
                               class="rounded-lg border border-stone-300 text-sm py-2 px-3 focus:ring-emerald-500 focus:border-emerald-500">
                    </div>
                    @error('newShotVelocity') <p class="text-xs text-red-600">{{ $message }}</p> @enderror
                    <button type="button" wire:click="addShot"
                            class="rounded-lg bg-stone-800 px-3 py-2 text-xs font-semibold text-white hover:bg-stone-900">Add shot</button>
                </div>
            </section>
        </div>

        {{-- Metadata form --}}
        <section class="rounded-xl border border-stone-200 bg-white p-5 space-y-4 ladder-print-hide">
            <div class="flex items-center justify-between">
                <p class="text-sm font-semibold text-stone-900">Session details</p>
                @if (session('metadata-saved'))
                    <span class="text-xs text-emerald-700">{{ session('metadata-saved') }}</span>
                @endif
            </div>
            <div class="grid sm:grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs font-medium text-stone-500 mb-1">Name</label>
                    <input type="text" wire:model="name"
                           class="w-full rounded-lg border border-stone-300 text-sm py-2 px-3 focus:ring-emerald-500 focus:border-emerald-500">
                    @error('name') <p class="text-xs text-red-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-xs font-medium text-stone-500 mb-1">Variable</label>
                    <select wire:model="variable"
                            class="w-full rounded-lg border border-stone-300 text-sm py-2 px-3 focus:ring-emerald-500 focus:border-emerald-500">
                        @foreach (\App\Enums\LadderVariable::cases() as $case)
                            <option value="{{ $case->value }}">{{ $case->label() }} ({{ $case->unit() }})</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-medium text-stone-500 mb-1">Fired on</label>
                    <input type="date" wire:model="fired_on"
                           class="w-full rounded-lg border border-stone-300 text-sm py-2 px-3 focus:ring-emerald-500 focus:border-emerald-500">
                </div>
                <div>
                    <label class="block text-xs font-medium text-stone-500 mb-1">Temperature (°C)</label>
                    <input type="number" step="0.1" wire:model="temperature_c"
                           class="w-full rounded-lg border border-stone-300 text-sm py-2 px-3 focus:ring-emerald-500 focus:border-emerald-500">
                </div>
                <div>
                    <label class="block text-xs font-medium text-stone-500 mb-1">Barrel</label>
                    <select wire:model="barrel_id"
                            class="w-full rounded-lg border border-stone-300 text-sm py-2 px-3 focus:ring-emerald-500 focus:border-emerald-500">
                        <option value="">— none —</option>
                        @foreach ($barrels as $b)
                            <option value="{{ $b->id }}">{{ $b->displayName() }} ({{ $b->round_count }} rd)</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-medium text-stone-500 mb-1">Ammo load</label>
                    <select wire:model="ammo_load_id"
                            class="w-full rounded-lg border border-stone-300 text-sm py-2 px-3 focus:ring-emerald-500 focus:border-emerald-500">
                        <option value="">— none —</option>
                        @foreach ($ammoLoads as $l)
                            <option value="{{ $l->id }}">{{ $l->displayName() }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-medium text-stone-500 mb-1">Barrel round count at session</label>
                    <input type="number" wire:model="barrel_round_count_at_session"
                           class="w-full rounded-lg border border-stone-300 text-sm py-2 px-3 focus:ring-emerald-500 focus:border-emerald-500">
                </div>
                <div>
                    <label class="block text-xs font-medium text-stone-500 mb-1">Powder</label>
                    <input type="text" wire:model="powder"
                           class="w-full rounded-lg border border-stone-300 text-sm py-2 px-3 focus:ring-emerald-500 focus:border-emerald-500">
                </div>
                <div>
                    <label class="block text-xs font-medium text-stone-500 mb-1">Bullet</label>
                    <input type="text" wire:model="bullet"
                           class="w-full rounded-lg border border-stone-300 text-sm py-2 px-3 focus:ring-emerald-500 focus:border-emerald-500">
                </div>
                <div>
                    <label class="block text-xs font-medium text-stone-500 mb-1">Brass</label>
                    <input type="text" wire:model="brass"
                           class="w-full rounded-lg border border-stone-300 text-sm py-2 px-3 focus:ring-emerald-500 focus:border-emerald-500">
                </div>
                <div>
                    <label class="block text-xs font-medium text-stone-500 mb-1">Primer</label>
                    <input type="text" wire:model="primer"
                           class="w-full rounded-lg border border-stone-300 text-sm py-2 px-3 focus:ring-emerald-500 focus:border-emerald-500">
                </div>
            </div>
            <div>
                <label class="block text-xs font-medium text-stone-500 mb-1">Notes</label>
                <textarea wire:model="notes" rows="3"
                          class="w-full rounded-lg border border-stone-300 text-sm py-2 px-3 focus:ring-emerald-500 focus:border-emerald-500"></textarea>
            </div>
            <button type="button" wire:click="saveMetadata"
                    class="rounded-lg bg-emerald-700 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-800 transition">
                Save details
            </button>
        </section>
    </div>
</div>
