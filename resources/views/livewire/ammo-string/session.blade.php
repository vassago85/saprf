<?php

use App\Models\AmmoLoad;
use App\Models\AmmoString;
use App\Models\AmmoStringShot;
use App\Models\Barrel;
use App\Models\LadderSession;
use App\Services\AmmoString\DTO\StringFinding;
use App\Services\AmmoString\StringAnalysis;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app')] class extends Component {
    public AmmoString $string;

    public string $label = '';

    public ?int $ammo_load_id = null;

    public ?int $barrel_id = null;

    public ?int $ladder_session_id = null;

    public string $fired_on = '';

    public string $temperature_c = '';

    public string $notes = '';

    public string $paste = '';

    public string $newShotVelocity = '';

    public function mount(AmmoString $string): void
    {
        $this->authorize('view', $string);
        $this->string = $string;
        $this->syncFormFromModel();
    }

    private function syncFormFromModel(): void
    {
        $this->label = $this->string->label ?? '';
        $this->ammo_load_id = $this->string->ammo_load_id;
        $this->barrel_id = $this->string->barrel_id;
        $this->ladder_session_id = $this->string->ladder_session_id;
        $this->fired_on = optional($this->string->fired_on)->format('Y-m-d') ?? '';
        $this->temperature_c = $this->string->temperature_c !== null ? (string) $this->string->temperature_c : '';
        $this->notes = $this->string->notes ?? '';
    }

    public function saveMetadata(): void
    {
        $this->authorize('update', $this->string);

        $data = $this->validate([
            'label' => ['required', 'string', 'max:120'],
            'ammo_load_id' => ['nullable', 'integer', 'exists:ammo_loads,id'],
            'barrel_id' => ['nullable', 'integer', 'exists:barrels,id'],
            'ladder_session_id' => ['nullable', 'integer', 'exists:ladder_sessions,id'],
            'fired_on' => ['nullable', 'date'],
            'temperature_c' => ['nullable', 'numeric'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);

        $this->string->fill([
            'label' => $data['label'],
            'ammo_load_id' => $data['ammo_load_id'] ?? null,
            'barrel_id' => $data['barrel_id'] ?? null,
            'ladder_session_id' => $data['ladder_session_id'] ?? null,
            'fired_on' => $data['fired_on'] ?: null,
            'temperature_c' => $data['temperature_c'] !== '' ? $data['temperature_c'] : null,
            'notes' => $data['notes'] ?? null,
        ])->save();

        $this->writeBackMeasuredSd();

        session()->flash('metadata-saved', 'Saved.');
    }

    /**
     * Parse the paste box. Any whitespace-separated number becomes a shot; any
     * line starting with # is a comment. Order in the paste = fire order.
     */
    public function applyPaste(): void
    {
        $this->authorize('update', $this->string);

        $tokens = [];
        foreach (preg_split('/\s+/', trim($this->paste)) as $tok) {
            if ($tok === '' || str_starts_with($tok, '#')) {
                continue;
            }
            if (! is_numeric($tok)) {
                continue;
            }
            $v = (float) $tok;
            // Guard against decimal-place slips: a chronograph doesn't read
            // below ~500 fps for anything real. Silently drop obvious noise.
            if ($v < 500.0 || $v > 5000.0) {
                continue;
            }
            $tokens[] = $v;
        }

        if ($tokens === []) {
            session()->flash('paste-warning', 'No usable velocities found in the paste.');

            return;
        }

        DB::transaction(function () use ($tokens) {
            $this->string->shots()->delete();
            foreach ($tokens as $i => $v) {
                AmmoStringShot::create([
                    'ammo_string_id' => $this->string->id,
                    'sequence' => $i + 1,
                    'velocity_fps' => $v,
                    'excluded' => false,
                ]);
            }
        });

        $this->paste = '';
        $this->writeBackMeasuredSd();
        session()->flash('paste-applied', 'Loaded '.count($tokens).' shots.');
    }

    public function addShot(): void
    {
        $this->authorize('update', $this->string);

        $velocity = (float) trim($this->newShotVelocity);
        if ($velocity < 500.0 || $velocity > 5000.0) {
            $this->addError('newShotVelocity', 'Give me a plausible velocity (500 – 5000 fps).');

            return;
        }

        $nextSeq = (int) ($this->string->shots()->max('sequence') ?? 0) + 1;
        AmmoStringShot::create([
            'ammo_string_id' => $this->string->id,
            'sequence' => $nextSeq,
            'velocity_fps' => $velocity,
            'excluded' => false,
        ]);

        $this->newShotVelocity = '';
        $this->writeBackMeasuredSd();
    }

    public function toggleShotExcluded(int $shotId): void
    {
        $this->authorize('update', $this->string);

        $shot = $this->string->shots()->where('id', $shotId)->firstOrFail();
        $shot->excluded = ! $shot->excluded;
        $shot->save();

        $this->writeBackMeasuredSd();
    }

    public function removeShot(int $shotId): void
    {
        $this->authorize('update', $this->string);

        $this->string->shots()->where('id', $shotId)->delete();
        $this->writeBackMeasuredSd();
    }

    /**
     * Snapshot the current n and SD to the linked ammo load's measured-SD
     * fields, so the load picker across the platform can show a confirmed
     * SD alongside the nickname. Silently no-ops when no load is linked.
     */
    private function writeBackMeasuredSd(): void
    {
        if ($this->string->ammo_load_id === null) {
            return;
        }

        $load = AmmoLoad::find($this->string->ammo_load_id);
        if ($load === null) {
            return;
        }
        // Belt-and-braces: only the string's owner can write to their own load.
        if ($load->user_id !== auth()->id()) {
            return;
        }

        $result = StringAnalysis::analyze($this->string->fresh(['shots']));
        if ($result->sd === null || $result->n < 2) {
            return;
        }

        $load->update([
            'measured_sd_fps' => round($result->sd, 2),
            'measured_sd_n' => $result->n,
            'measured_sd_at' => now(),
            'measured_sd_string_id' => $this->string->id,
        ]);
    }

    public function analysis()
    {
        return StringAnalysis::analyze($this->string->fresh(['shots']) ?? $this->string);
    }

    public function with(): array
    {
        $result = $this->analysis();
        $chart = $this->buildChartData($result);

        return [
            'result' => $result,
            'chart' => $chart,
            'ammoLoads' => AmmoLoad::forUser(auth()->id())->active()->orderBy('nickname')->get(),
            'barrels' => Barrel::forUser(auth()->id())->orderBy('label')->get(),
            'ladderSessions' => LadderSession::forUser(auth()->id())->orderByDesc('fired_on')->limit(50)->get(),
        ];
    }

    /**
     * Build a compact SVG-friendly chart payload — velocity vs shot number,
     * with the string mean, a ±1 SD envelope, and the trend line overlay.
     * All coordinates in the 800x360 viewBox.
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

        $shots = collect($result->shots)->filter(fn ($s) => ! $s['excluded'])->values();
        $allShots = collect($result->shots);

        if ($shots->isEmpty()) {
            return [
                'width' => $width, 'height' => $height,
                'marginLeft' => $marginLeft, 'marginRight' => $marginRight,
                'marginTop' => $marginTop, 'marginBottom' => $marginBottom,
                'plotX' => $plotX, 'plotY' => $plotY,
                'plotWidth' => $plotWidth, 'plotHeight' => $plotHeight,
                'hasData' => false,
                'xTicks' => [], 'yTicks' => [], 'points' => [],
                'meanLine' => null, 'sdBand' => null,
                'trendStart' => null, 'trendEnd' => null,
            ];
        }

        // x-range: shot sequence (padded by half a unit so shots don't sit on
        // the frame). Use all shots including excluded so the plot doesn't
        // reflow when toggles change.
        $xMin = (float) $allShots->min('sequence');
        $xMax = (float) $allShots->max('sequence');
        if ($xMin === $xMax) {
            $xMin -= 0.5;
            $xMax += 0.5;
        } else {
            $xMin -= 0.5;
            $xMax += 0.5;
        }

        // y-range: shot velocities, with ±1 SD band accommodated. Snap to
        // nice tick values so labels read as round numbers.
        $vs = $shots->pluck('velocity')->all();
        $yDataMin = min($vs);
        $yDataMax = max($vs);
        if ($result->sd !== null && $result->mean !== null) {
            $yDataMin = min($yDataMin, $result->mean - $result->sd);
            $yDataMax = max($yDataMax, $result->mean + $result->sd);
        }
        if ($yDataMin === $yDataMax) {
            $yDataMin -= 1.0;
            $yDataMax += 1.0;
        }
        $padding = ($yDataMax - $yDataMin) * 0.15;
        [$yMin, $yMax, $yTickValues] = self::niceAxis($yDataMin - $padding, $yDataMax + $padding);

        $mapX = function (float $v) use ($xMin, $xMax, $plotX, $plotWidth) {
            return $plotX + ($v - $xMin) / ($xMax - $xMin) * $plotWidth;
        };
        $mapY = function (float $v) use ($yMin, $yMax, $plotY, $plotHeight) {
            return $plotY + ($yMax - $v) / ($yMax - $yMin) * $plotHeight;
        };

        // x-ticks: one per shot number, but throttle when there are many so
        // labels don't overlap. Rough rule: aim for ~15 labels max.
        $xTicks = [];
        $seqs = $allShots->pluck('sequence')->sort()->unique()->values();
        $step = max(1, (int) ceil($seqs->count() / 15));
        foreach ($seqs as $i => $seq) {
            if ($i === 0 || $i === $seqs->count() - 1 || $seq % $step === 0) {
                $xTicks[] = ['x' => $mapX((float) $seq), 'label' => (string) $seq];
            }
        }

        $yTicks = [];
        foreach ($yTickValues as $v) {
            $yTicks[] = ['y' => $mapY($v), 'label' => (string) (int) round($v)];
        }

        // Points — all shots (excluded rendered faded).
        $points = [];
        foreach ($allShots as $s) {
            $points[] = [
                'x' => $mapX((float) $s['sequence']),
                'y' => $mapY((float) $s['velocity']),
                'sequence' => (int) $s['sequence'],
                'velocity' => (float) $s['velocity'],
                'excluded' => (bool) $s['excluded'],
                'residualFromMean' => $s['residualFromMean'],
            ];
        }

        // Mean line spans the plot x-range.
        $meanLine = null;
        if ($result->mean !== null) {
            $meanLine = [
                'y' => $mapY($result->mean),
                'x1' => $plotX,
                'x2' => $plotX + $plotWidth,
                'mean' => $result->mean,
            ];
        }

        // ±1 SD band — parallel to the mean line.
        $sdBand = null;
        if ($result->sd !== null && $result->mean !== null) {
            $sdBand = [
                'upper' => $mapY($result->mean + $result->sd),
                'lower' => $mapY($result->mean - $result->sd),
                'x1' => $plotX,
                'x2' => $plotX + $plotWidth,
            ];
        }

        // Trend line overlay — from x=1 to x=n.
        $trendStart = null;
        $trendEnd = null;
        if ($result->trend !== null) {
            $seqMin = (int) $seqs->first();
            $seqMax = (int) $seqs->last();
            $trendStart = [
                'x' => $mapX((float) $seqMin),
                'y' => $mapY($result->trend->predict($seqMin)),
            ];
            $trendEnd = [
                'x' => $mapX((float) $seqMax),
                'y' => $mapY($result->trend->predict($seqMax)),
            ];
        }

        return [
            'width' => $width, 'height' => $height,
            'marginLeft' => $marginLeft, 'marginRight' => $marginRight,
            'marginTop' => $marginTop, 'marginBottom' => $marginBottom,
            'plotX' => $plotX, 'plotY' => $plotY,
            'plotWidth' => $plotWidth, 'plotHeight' => $plotHeight,
            'hasData' => true,
            'xTicks' => $xTicks, 'yTicks' => $yTicks,
            'points' => $points,
            'meanLine' => $meanLine,
            'sdBand' => $sdBand,
            'trendStart' => $trendStart,
            'trendEnd' => $trendEnd,
        ];
    }

    /**
     * Same "nice" axis snapping as the ladder analyser. Extracted here rather
     * than pulled from a shared location so the two Volt components stay
     * self-contained; if a third analyser lands, factor to a helper.
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
    <style>
        @media print {
            body { background: white !important; }
            .ladder-print-hide, aside, header, nav, footer { display: none !important; }
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
                <a href="{{ route('ammo-strings.index') }}" class="text-sm text-emerald-700 font-medium hover:text-emerald-800">&larr; All strings</a>
                <h1 class="mt-1 font-heading text-3xl font-bold text-stone-900 tracking-tight">{{ $string->label }}</h1>
                <p class="mt-1 text-sm text-stone-500">
                    Confirmation string &middot; fired {{ optional($string->fired_on)->format('j M Y') }}
                    @if ($string->ammoLoad)
                        &middot; <span class="text-stone-700 font-medium">{{ $string->ammoLoad->displayName() }}</span>
                    @endif
                    @if ($string->barrel)
                        &middot; on <span class="text-stone-700 font-medium">{{ $string->barrel->label }}</span>
                    @endif
                </p>
            </div>
            <div class="flex items-center gap-3">
                <a href="{{ route('ammo-strings.export.csv', $string) }}"
                   class="inline-flex items-center gap-1.5 rounded-lg border border-stone-300 bg-white px-3 py-2 text-xs font-semibold text-stone-700 hover:bg-stone-50 transition">
                    Export CSV
                </a>
                <button type="button" onclick="window.print()"
                        class="inline-flex items-center gap-1.5 rounded-lg border border-stone-300 bg-white px-3 py-2 text-xs font-semibold text-stone-700 hover:bg-stone-50 transition">
                    Print
                </button>
                <form method="POST" action="{{ route('ammo-strings.destroy', $string) }}"
                      onsubmit="return confirm('Delete this string?');">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="rounded-lg border border-red-200 bg-white px-3 py-2 text-xs font-semibold text-red-700 hover:bg-red-50 transition">
                        Delete
                    </button>
                </form>
            </div>
        </div>

        {{-- Summary cards --}}
        <section class="grid grid-cols-2 sm:grid-cols-4 gap-3">
            <div class="rounded-xl border border-stone-200 bg-white p-4" title="Number of non-excluded shots in the string. Excluded shots stay visible in the row list but drop out of every calculation.">
                <p class="text-[11px] font-semibold uppercase tracking-wider text-stone-500 ladder-hint">Shots</p>
                <p class="mt-1 text-lg font-bold text-stone-900">{{ $result->n }}</p>
                <p class="mt-1 text-xs text-stone-500">
                    @if ($result->n < $result->totalShots)
                        {{ $result->totalShots - $result->n }} excluded
                    @else
                        of {{ $result->totalShots }}
                    @endif
                </p>
            </div>
            <div class="rounded-xl border border-stone-200 bg-white p-4" title="Arithmetic mean velocity across every non-excluded shot.">
                <p class="text-[11px] font-semibold uppercase tracking-wider text-stone-500 ladder-hint">Mean</p>
                <p class="mt-1 text-lg font-bold text-stone-900">
                    {{ $result->mean !== null ? number_format($result->mean, 1).' fps' : '—' }}
                </p>
                <p class="mt-1 text-xs text-stone-500">
                    ES {{ $result->es !== null ? number_format($result->es, 1).' fps' : '—' }}
                </p>
            </div>
            <div class="rounded-xl border border-stone-200 bg-white p-4" title="Sample standard deviation. The 90% confidence interval below tells you how tightly this n has pinned down the true SD — small n means a wide honest range.">
                <p class="text-[11px] font-semibold uppercase tracking-wider text-stone-500 ladder-hint">SD</p>
                <p class="mt-1 text-lg font-bold text-stone-900">
                    {{ $result->sd !== null ? number_format($result->sd, 2).' fps' : '—' }}
                </p>
                <p class="mt-1 text-xs text-stone-500">
                    @if ($result->sdCiLower !== null)
                        90% CI {{ number_format($result->sdCiLower, 1) }} – {{ number_format($result->sdCiUpper, 1) }} · df {{ $result->sdDf }}
                    @else
                        need ≥2 shots
                    @endif
                </p>
            </div>
            <div class="rounded-xl border border-stone-200 bg-white p-4" title="Regression of velocity against shot number. Positive slope = string climbing (usually barrel heating), negative = drifting down. p<0.05 means the trend is real, not noise.">
                <p class="text-[11px] font-semibold uppercase tracking-wider text-stone-500 ladder-hint">Trend</p>
                <p class="mt-1 text-lg font-bold {{ $result->trend?->isSignificant() ? 'text-amber-700' : 'text-stone-900' }}">
                    {{ $result->trend !== null ? number_format($result->trend->slope, 2).' fps/shot' : '—' }}
                </p>
                <p class="mt-1 text-xs text-stone-500">
                    @if ($result->trend !== null)
                        p = {{ number_format($result->trend->slopeP, 3) }} · R² {{ number_format($result->trend->rSquared, 2) }}
                    @else
                        need ≥3 shots
                    @endif
                </p>
            </div>
        </section>

        {{-- Findings panel --}}
        @if (! empty($result->findings))
            <section class="space-y-2">
                @foreach ($result->findings as $finding)
                    @php
                        $classes = match ($finding->severity) {
                            StringFinding::SEVERITY_OK => 'border-emerald-200 bg-emerald-50',
                            StringFinding::SEVERITY_WARN => 'border-amber-200 bg-amber-50',
                            StringFinding::SEVERITY_BAD => 'border-red-200 bg-red-50',
                            default => 'border-stone-200 bg-white',
                        };
                        $barColour = match ($finding->severity) {
                            StringFinding::SEVERITY_OK => 'bg-emerald-600',
                            StringFinding::SEVERITY_WARN => 'bg-amber-600',
                            StringFinding::SEVERITY_BAD => 'bg-red-600',
                            default => 'bg-stone-400',
                        };
                    @endphp
                    <div class="rounded-xl border {{ $classes }} p-4 flex gap-3">
                        <div class="w-1 rounded {{ $barColour }} shrink-0"></div>
                        <div>
                            <p class="text-sm font-semibold text-stone-900">{{ $finding->title }}</p>
                            <p class="mt-1 text-sm text-stone-700">{!! $finding->body !!}</p>
                        </div>
                    </div>
                @endforeach
            </section>
        @endif

        {{-- Chart --}}
        <section class="rounded-xl border border-stone-200 bg-white p-4">
            <div class="flex items-center justify-between mb-3 flex-wrap gap-2">
                <div>
                    <p class="text-sm font-semibold text-stone-900">Velocity across the string</p>
                    <p class="text-xs text-stone-500">Every shot in fire order, with the string mean, ±1 SD envelope, and trend line.</p>
                </div>
                <div class="flex items-center gap-4 text-xs text-stone-600 flex-wrap justify-end">
                    <span class="inline-flex items-center gap-1"><span class="inline-block h-2.5 w-2.5 rounded-full bg-emerald-700"></span>Shot</span>
                    <span class="inline-flex items-center gap-1"><span class="inline-block h-2.5 w-2.5 rounded-full bg-stone-300"></span>Excluded</span>
                    <span class="inline-flex items-center gap-1">
                        <svg width="18" height="6" class="shrink-0" aria-hidden="true"><line x1="0" y1="3" x2="18" y2="3" stroke="#047857" stroke-width="2" /></svg>
                        Mean
                    </span>
                    @if ($chart['sdBand'] !== null)
                        <span class="inline-flex items-center gap-1">
                            <span class="inline-block h-2.5 w-3.5 rounded-sm" style="background-color: rgba(4, 120, 87, 0.15);"></span>
                            ±1 SD
                        </span>
                    @endif
                    @if ($chart['trendStart'] !== null)
                        <span class="inline-flex items-center gap-1">
                            <svg width="18" height="6" class="shrink-0" aria-hidden="true"><line x1="0" y1="3" x2="18" y2="3" stroke="#b45309" stroke-width="2" stroke-dasharray="4 3" /></svg>
                            Trend
                        </span>
                    @endif
                </div>
            </div>
            @if ($chart['hasData'])
                <svg viewBox="0 0 {{ $chart['width'] }} {{ $chart['height'] }}" class="w-full">
                    <rect x="{{ $chart['plotX'] }}" y="{{ $chart['plotY'] }}"
                          width="{{ $chart['plotWidth'] }}" height="{{ $chart['plotHeight'] }}"
                          fill="#fafaf9" stroke="#e7e5e4" />

                    @foreach ($chart['yTicks'] as $tick)
                        <line x1="{{ $chart['plotX'] }}" y1="{{ $tick['y'] }}"
                              x2="{{ $chart['plotX'] + $chart['plotWidth'] }}" y2="{{ $tick['y'] }}"
                              stroke="#e7e5e4" stroke-dasharray="2 3" />
                        <text x="{{ $chart['plotX'] - 8 }}" y="{{ $tick['y'] + 4 }}"
                              font-size="11" fill="#78716c" text-anchor="end">{{ $tick['label'] }}</text>
                    @endforeach

                    @foreach ($chart['xTicks'] as $tick)
                        <line x1="{{ $tick['x'] }}" y1="{{ $chart['plotY'] + $chart['plotHeight'] }}"
                              x2="{{ $tick['x'] }}" y2="{{ $chart['plotY'] + $chart['plotHeight'] + 4 }}"
                              stroke="#78716c" />
                        <text x="{{ $tick['x'] }}" y="{{ $chart['plotY'] + $chart['plotHeight'] + 18 }}"
                              font-size="11" fill="#78716c" text-anchor="middle">{{ $tick['label'] }}</text>
                    @endforeach

                    {{-- ±1 SD band. --}}
                    @if ($chart['sdBand'] !== null)
                        <rect x="{{ $chart['sdBand']['x1'] }}" y="{{ $chart['sdBand']['upper'] }}"
                              width="{{ $chart['sdBand']['x2'] - $chart['sdBand']['x1'] }}"
                              height="{{ $chart['sdBand']['lower'] - $chart['sdBand']['upper'] }}"
                              fill="#047857" fill-opacity="0.10" stroke="none" />
                    @endif

                    {{-- Mean line. --}}
                    @if ($chart['meanLine'] !== null)
                        <line x1="{{ $chart['meanLine']['x1'] }}" y1="{{ $chart['meanLine']['y'] }}"
                              x2="{{ $chart['meanLine']['x2'] }}" y2="{{ $chart['meanLine']['y'] }}"
                              stroke="#047857" stroke-width="1.5" />
                    @endif

                    {{-- Trend line overlay. --}}
                    @if ($chart['trendStart'] !== null)
                        <line x1="{{ $chart['trendStart']['x'] }}" y1="{{ $chart['trendStart']['y'] }}"
                              x2="{{ $chart['trendEnd']['x'] }}" y2="{{ $chart['trendEnd']['y'] }}"
                              stroke="#b45309" stroke-width="2" stroke-dasharray="5 4" />
                    @endif

                    {{-- Shot points. --}}
                    @foreach ($chart['points'] as $pt)
                        @if ($pt['excluded'])
                            <circle cx="{{ $pt['x'] }}" cy="{{ $pt['y'] }}" r="4"
                                    fill="#d6d3d1" stroke="#a8a29e" stroke-width="1" opacity="0.7" />
                        @else
                            <circle cx="{{ $pt['x'] }}" cy="{{ $pt['y'] }}" r="4"
                                    fill="#047857" />
                        @endif
                    @endforeach

                    <text x="{{ $chart['width'] / 2 }}" y="{{ $chart['height'] - 6 }}"
                          font-size="12" fill="#57534e" text-anchor="middle">Shot number</text>
                    <text x="14" y="{{ $chart['plotY'] + $chart['plotHeight'] / 2 }}"
                          font-size="12" fill="#57534e"
                          transform="rotate(-90 14 {{ $chart['plotY'] + $chart['plotHeight'] / 2 }})"
                          text-anchor="middle">Velocity (fps)</text>
                </svg>
            @else
                <p class="text-center text-sm text-stone-400 py-10">Paste velocities to see the chart.</p>
            @endif
        </section>

        {{-- Shot table --}}
        <section class="rounded-xl border border-stone-200 bg-white overflow-hidden">
            <div class="px-4 py-3 border-b border-stone-100">
                <p class="text-sm font-semibold text-stone-900">Shots</p>
                <p class="text-xs text-stone-500">Fire order. Exclude a shot to drop it from the analysis without deleting it.</p>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-stone-100 text-sm">
                    <thead class="bg-stone-50 text-xs uppercase tracking-wider text-stone-500">
                        <tr>
                            <th class="px-3 py-2 text-right">#</th>
                            <th class="px-3 py-2 text-right">Velocity</th>
                            <th class="px-3 py-2 text-right" title="Shot velocity minus the string mean. Positive = above the mean, negative = below.">
                                <span class="ladder-hint">Δ mean</span>
                            </th>
                            <th class="px-3 py-2 text-right" title="Shot velocity minus what the OLS trend line predicts for this shot number. Highlights barrel-heating drift.">
                                <span class="ladder-hint">Δ trend</span>
                            </th>
                            <th class="px-3 py-2 text-center ladder-print-hide">In</th>
                            <th class="px-3 py-2 ladder-print-hide"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-stone-100">
                        @php $rawShots = $string->shots; @endphp
                        @foreach ($rawShots as $shot)
                            @php
                                $row = collect($result->shots)->firstWhere('sequence', (int) $shot->sequence);
                            @endphp
                            <tr class="{{ $shot->excluded ? 'bg-stone-50/60 text-stone-400' : '' }}">
                                <td class="px-3 py-2 text-right tabular-nums font-semibold {{ $shot->excluded ? 'line-through' : 'text-stone-900' }}">{{ $shot->sequence }}</td>
                                <td class="px-3 py-2 text-right tabular-nums {{ $shot->excluded ? 'line-through' : 'text-stone-800' }}">{{ number_format((float) $shot->velocity_fps, 1) }}</td>
                                <td class="px-3 py-2 text-right tabular-nums text-stone-500">
                                    {{ $row && $row['residualFromMean'] !== null ? ($row['residualFromMean'] >= 0 ? '+' : '').number_format($row['residualFromMean'], 1) : '—' }}
                                </td>
                                <td class="px-3 py-2 text-right tabular-nums text-stone-500">
                                    {{ $row && $row['residualFromTrend'] !== null ? ($row['residualFromTrend'] >= 0 ? '+' : '').number_format($row['residualFromTrend'], 1) : '—' }}
                                </td>
                                <td class="px-3 py-2 text-center ladder-print-hide">
                                    <input type="checkbox" wire:click="toggleShotExcluded({{ $shot->id }})"
                                           @checked(! $shot->excluded)
                                           class="rounded border-stone-300 text-emerald-600 focus:ring-emerald-500">
                                </td>
                                <td class="px-3 py-2 text-right ladder-print-hide">
                                    <button type="button" wire:click="removeShot({{ $shot->id }})"
                                            class="text-xs text-stone-400 hover:text-red-600"
                                            onclick="return confirm('Remove this shot?');">Remove</button>
                                </td>
                            </tr>
                        @endforeach
                        @if ($rawShots->isEmpty())
                            <tr><td colspan="6" class="px-3 py-6 text-center text-sm text-stone-400">No shots yet — paste some below.</td></tr>
                        @endif
                    </tbody>
                </table>
            </div>
        </section>

        {{-- Editor pane --}}
        <div class="grid gap-6 md:grid-cols-2 ladder-print-hide">
            <section class="rounded-xl border border-stone-200 bg-white p-5 space-y-3">
                <div>
                    <p class="text-sm font-semibold text-stone-900">Paste the string</p>
                    <p class="text-xs text-stone-500">One velocity per line, in fire order. Anything not a number is ignored.</p>
                </div>
                <textarea wire:model="paste" rows="10" placeholder="2795.2&#10;2802.4&#10;2794.6&#10;2800.1&#10;2797.8"
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

            <section class="rounded-xl border border-stone-200 bg-white p-5 space-y-4">
                <div>
                    <p class="text-sm font-semibold text-stone-900">Add a single shot</p>
                    <p class="text-xs text-stone-500">Appended after the last recorded shot.</p>
                </div>
                <div class="flex items-center gap-2">
                    <input type="number" step="0.1" wire:model="newShotVelocity"
                           placeholder="e.g. 2795.2"
                           class="flex-1 rounded-lg border border-stone-300 text-sm py-2 px-3 focus:ring-emerald-500 focus:border-emerald-500">
                    <button type="button" wire:click="addShot"
                            class="rounded-lg bg-stone-800 px-3 py-2 text-xs font-semibold text-white hover:bg-stone-900">Add shot</button>
                </div>
                @error('newShotVelocity') <p class="text-xs text-red-600">{{ $message }}</p> @enderror
            </section>
        </div>

        {{-- Metadata --}}
        <section class="rounded-xl border border-stone-200 bg-white p-5 space-y-4 ladder-print-hide">
            <div>
                <p class="text-sm font-semibold text-stone-900">Metadata</p>
                <p class="text-xs text-stone-500">Link this string to the load and barrel it was fired with. The load's measured SD will update whenever you save shots.</p>
            </div>
            <div class="grid sm:grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs font-medium text-stone-500 mb-1">Label</label>
                    <input type="text" wire:model.blur="label" maxlength="120"
                           class="w-full rounded-lg border border-stone-300 text-sm py-2 px-3 focus:ring-emerald-500 focus:border-emerald-500">
                </div>
                <div>
                    <label class="block text-xs font-medium text-stone-500 mb-1">Fired on</label>
                    <input type="date" wire:model.blur="fired_on"
                           class="w-full rounded-lg border border-stone-300 text-sm py-2 px-3 focus:ring-emerald-500 focus:border-emerald-500">
                </div>
                <div>
                    <label class="block text-xs font-medium text-stone-500 mb-1">Ammo load</label>
                    <select wire:model.blur="ammo_load_id" class="w-full rounded-lg border border-stone-300 text-sm py-2 px-3 focus:ring-emerald-500 focus:border-emerald-500">
                        <option value="">—</option>
                        @foreach ($ammoLoads as $l)
                            <option value="{{ $l->id }}">{{ $l->displayName() }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-medium text-stone-500 mb-1">Barrel</label>
                    <select wire:model.blur="barrel_id" class="w-full rounded-lg border border-stone-300 text-sm py-2 px-3 focus:ring-emerald-500 focus:border-emerald-500">
                        <option value="">—</option>
                        @foreach ($barrels as $b)
                            <option value="{{ $b->id }}">{{ $b->label }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-medium text-stone-500 mb-1">Ladder session (optional)</label>
                    <select wire:model.blur="ladder_session_id" class="w-full rounded-lg border border-stone-300 text-sm py-2 px-3 focus:ring-emerald-500 focus:border-emerald-500">
                        <option value="">—</option>
                        @foreach ($ladderSessions as $ls)
                            <option value="{{ $ls->id }}">{{ $ls->name }} · {{ optional($ls->fired_on)->format('j M Y') }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-medium text-stone-500 mb-1">Temperature (°C)</label>
                    <input type="number" step="0.1" wire:model.blur="temperature_c"
                           class="w-full rounded-lg border border-stone-300 text-sm py-2 px-3 focus:ring-emerald-500 focus:border-emerald-500">
                </div>
                <div class="sm:col-span-2">
                    <label class="block text-xs font-medium text-stone-500 mb-1">Notes</label>
                    <textarea wire:model.blur="notes" rows="3"
                              class="w-full rounded-lg border border-stone-300 text-sm py-2 px-3 focus:ring-emerald-500 focus:border-emerald-500"></textarea>
                </div>
            </div>
            <button type="button" wire:click="saveMetadata"
                    class="rounded-lg bg-emerald-700 px-4 py-2 text-xs font-semibold text-white hover:bg-emerald-800 transition">
                Save metadata
            </button>
            @if (session('metadata-saved'))
                <span class="ml-2 text-xs text-emerald-700">{{ session('metadata-saved') }}</span>
            @endif
        </section>
    </div>
</div>
