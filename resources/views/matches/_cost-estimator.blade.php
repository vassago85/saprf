@php
    $standalone = (bool) ($standalone ?? false);
    $settings = app(\App\Services\SettingsService::class);
    $saprfType = $settings->get('saprf_fee_type', 'percentage');
    $saprfValue = (float) $settings->get('saprf_fee_value', $settings->get('saprf_fee_percentage', 5));
    $platformType = $settings->get('platform_fee_type', 'percentage');
    $platformValue = (float) $settings->get('platform_fee_value', $settings->get('platform_fee_percentage', 5));
    $gatewayPct = (float) $settings->get('estimated_gateway_fee_percentage', 3.5);
    $gatewayFlat = (float) $settings->get('estimated_gateway_flat_fee', 2);
    $nmSurcharge = (float) $settings->get('non_member_surcharge', 0);
    $lmSurcharge = (float) $settings->get('lapsed_member_surcharge', 0);
    // Club-entry split applies to non-members on club and provincial
    // matches. The two percents live in site settings so ExCo can tune
    // them without a deploy; defaults match the proposal (3% + 3%).
    $clubSaprfPct = (float) $settings->get('club_entry_saprf_percent', 3);
    $clubPlatformPct = (float) $settings->get('club_entry_platform_percent', 3);

    $rateMatch = isset($match) ? $match : null;
    if ($rateMatch && $rateMatch->saprf_fee_type !== null && $rateMatch->saprf_fee_value !== null) {
        $saprfType = (string) $rateMatch->saprf_fee_type;
        $saprfValue = (float) $rateMatch->saprf_fee_value;
    }
    if ($rateMatch && $rateMatch->platform_fee_type !== null && $rateMatch->platform_fee_value !== null) {
        $platformType = (string) $rateMatch->platform_fee_type;
        $platformValue = (float) $rateMatch->platform_fee_value;
    }

    $supportsClubEntry = $rateMatch?->supportsClubEntry() ?? false;
    $clubEntryFee = $rateMatch?->club_entry_fee !== null ? (float) $rateMatch->club_entry_fee : null;

    $initialFee = $standalone ? (float) ($rateMatch->active_member_fee ?? 0) : 0;
    $initialShooters = $standalone ? (int) old('estimated_shooters', $rateMatch->estimated_shooters ?? 0) : 0;
    $showActualCosts = $standalone && (bool) ($viewerIsMatchDirector ?? false);
    $costRows = $showActualCosts ? collect($expenses ?? []) : collect();
    $fixedRows = $costRows->where('cost_type', '!=', 'per_shooter');
    $variableRows = $costRows->where('cost_type', 'per_shooter');
    $fixedCosts = round((float) $fixedRows->sum(fn ($expense) => (float) $expense->amount), 2);
    $variableUnit = round((float) $variableRows->sum(fn ($expense) => (float) $expense->amount), 2);
@endphp

<div @class(['sm:col-span-2' => ! $standalone])
     x-data="costEstimator({
         standalone: {{ $standalone ? 'true' : 'false' }},
         initialFee: {{ $initialFee }},
         initialShooters: {{ $initialShooters }},
         saprfType: '{{ $saprfType }}',
         saprfValue: {{ $saprfValue }},
         platformType: '{{ $platformType }}',
         platformValue: {{ $platformValue }},
         gatewayPct: {{ $gatewayPct }},
         gatewayFlat: {{ $gatewayFlat }},
         nmSurcharge: {{ $nmSurcharge }},
         lmSurcharge: {{ $lmSurcharge }},
         supportsClubEntry: {{ $supportsClubEntry ? 'true' : 'false' }},
         clubEntryFee: {{ $clubEntryFee !== null ? $clubEntryFee : 'null' }},
         clubSaprfPct: {{ $clubSaprfPct }},
         clubPlatformPct: {{ $clubPlatformPct }},
         fixedCosts: {{ $fixedCosts }},
         variableUnit: {{ $variableUnit }},
     })"
     x-init="$nextTick(() => {
        if (standalone) {
            calculate();
            return;
        }

        const feeEl = document.getElementById('active_member_fee');
        if(feeEl) feeEl.addEventListener('input', () => calculate());

        const clubFeeEl = document.getElementById('club_entry_fee');
        if(clubFeeEl) clubFeeEl.addEventListener('input', () => calculate());

        const seriesEl = document.getElementById('series_level');
        if(seriesEl) seriesEl.addEventListener('change', () => {
            supportsClubEntry = ['club','provincial'].includes(seriesEl.value);
            calculate();
        });

        const shootersEl = document.getElementById('estimated_shooters');
        if(shootersEl) {
            const initial = parseInt(shootersEl.value, 10);
            if(!isNaN(initial) && initial > 0) shooters = initial;
            shootersEl.addEventListener('input', () => {
                const v = parseInt(shootersEl.value, 10);
                shooters = (!isNaN(v) && v > 0) ? v : 0;
            });
            $watch('shooters', v => {
                if(String(shootersEl.value) !== String(v ?? '')) {
                    shootersEl.value = (v === null || v === undefined || v === '' || isNaN(v)) ? '' : v;
                }
            });
        }

        calculate();
     })"
     x-effect="shooters; calculate()">

    <div class="rounded-xl border border-stone-200 bg-white shadow-sm p-5 space-y-4">
        <div class="flex items-center justify-between gap-3">
            <h3 class="text-sm font-semibold text-stone-900">Cost Estimator</h3>
            <span class="text-xs text-stone-400">{{ $standalone ? 'Estimated or actual' : 'Per shooter estimate' }}</span>
        </div>

        @if($standalone)
            @php
                $registeredShooters = (int) ($registeredShooters ?? 0);
                $registeredSaprf = (float) ($registeredSaprf ?? 0);
                $registeredPlatform = (float) ($registeredPlatform ?? 0);
                $registeredGateway = (float) ($registeredGateway ?? 0);
                $registeredNet = (float) ($registeredNet ?? 0);
                $variableCosts = round($variableUnit * $registeredShooters, 2);
                $leftAfterCosts = round($registeredNet - $fixedCosts - $variableCosts, 2);
            @endphp

            <div class="grid gap-4 lg:grid-cols-2">
                <div class="rounded-lg border border-stone-200 bg-stone-50 p-4 space-y-3 text-sm">
                    <div>
                        <h4 class="font-semibold text-stone-900">Estimated</h4>
                        <p class="mt-0.5 text-[11px] text-stone-500">Every shooter pays the full adult entry fee.</p>
                    </div>

                    <form method="POST" action="{{ route('matches.estimated-shooters.update', $match) }}" class="space-y-2">
                        @csrf
                        @method('PUT')
                        <label for="cost_estimator_shooters" class="block text-xs font-medium text-stone-500">Estimated shooters</label>
                        <div class="flex flex-wrap items-center gap-2">
                            <input type="number" name="estimated_shooters" id="cost_estimator_shooters" min="1" max="999" required
                                   value="{{ $initialShooters > 0 ? $initialShooters : '' }}"
                                   x-model.number="shooters"
                                   placeholder="e.g. 30"
                                   class="w-28 rounded-lg border border-stone-300 px-3 py-2 text-sm focus:border-emerald-500 focus:ring-emerald-500" />
                            <button type="submit" class="rounded-lg bg-emerald-700 px-3 py-2 text-xs font-semibold text-white hover:bg-emerald-800">
                                Save estimate
                            </button>
                        </div>
                        @error('estimated_shooters')
                            <p class="text-xs text-red-600">{{ $message }}</p>
                        @enderror
                    </form>

                    <div class="space-y-2">
                        <div class="flex justify-between">
                            <span class="text-stone-600">Adult entry fee</span>
                            <span class="font-semibold tabular-nums text-stone-900">R <span x-text="fmt(fee)"></span></span>
                        </div>
                        <div class="flex justify-between font-semibold">
                            <span class="text-stone-800">Full adult fees</span>
                            <span class="tabular-nums text-stone-900">R <span x-text="fmt(fee * (parseInt(shooters, 10) || 0))"></span></span>
                        </div>
                        <div class="flex justify-between text-xs text-stone-500">
                            <span>SAPRF levy</span>
                            <span class="tabular-nums text-red-600">− R <span x-text="fmt(saprfFee * (parseInt(shooters, 10) || 0))"></span></span>
                        </div>
                        <div class="flex justify-between text-xs text-stone-500">
                            <span>Platform fee</span>
                            <span class="tabular-nums text-red-600">− R <span x-text="fmt(platformFee * (parseInt(shooters, 10) || 0))"></span></span>
                        </div>
                        <div class="flex justify-between text-xs text-stone-500">
                            <span>Gateway fee</span>
                            <span class="tabular-nums text-red-600">− R <span x-text="fmt(gatewayFee * (parseInt(shooters, 10) || 0))"></span></span>
                        </div>
                        <div class="flex justify-between border-t border-stone-200 pt-2 font-semibold">
                            <span class="text-stone-800">Net before match costs</span>
                            <span class="tabular-nums text-emerald-700">R <span x-text="fmt(netMd * (parseInt(shooters, 10) || 0))"></span></span>
                        </div>
                        @if($showActualCosts)
                            <div class="flex justify-between text-xs text-stone-500">
                                <span>Fixed costs</span>
                                <span class="tabular-nums text-red-600">− R <span x-text="fmt(fixedCosts)"></span></span>
                            </div>
                            <div class="flex justify-between text-xs text-stone-500">
                                <span>Per-shooter costs</span>
                                <span class="tabular-nums text-red-600">− R <span x-text="fmt(variableUnit * (parseInt(shooters, 10) || 0))"></span></span>
                            </div>
                            <div class="flex justify-between border-t border-stone-200 pt-2 font-bold">
                                <span class="text-stone-900">Left after costs</span>
                                <span class="tabular-nums" :class="estimatedLeft() >= 0 ? 'text-emerald-700' : 'text-red-600'" x-text="'R ' + fmt(estimatedLeft())"></span>
                            </div>
                        @endif
                    </div>
                </div>

                <div class="rounded-lg border border-emerald-200 bg-emerald-50/40 p-4 space-y-3 text-sm">
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <h4 class="font-semibold text-stone-900">Actual</h4>
                            <p class="mt-0.5 text-[11px] text-stone-500">SAPRF, platform and gateway are already out of this net.</p>
                        </div>
                        <span class="text-right text-xs text-stone-500">
                            <span class="block font-semibold text-stone-900">Registered shooters</span>
                            {{ $registeredShooters }}
                        </span>
                    </div>

                    <div class="space-y-2">
                        <div class="flex justify-between gap-3 font-semibold">
                            <span class="text-stone-800">Net after SAPRF, platform and gateway</span>
                            <span class="shrink-0 tabular-nums text-emerald-700">R {{ number_format($registeredNet, 2) }}</span>
                        </div>
                        <p class="text-[11px] text-stone-500">
                            Already removed: SAPRF R {{ number_format($registeredSaprf, 2) }},
                            platform R {{ number_format($registeredPlatform, 2) }},
                            gateway R {{ number_format($registeredGateway, 2) }}.
                        </p>

                        @if($showActualCosts)
                            <div class="space-y-1 border-t border-emerald-200 pt-2">
                                <div class="flex justify-between text-xs font-medium text-stone-600">
                                    <span>Fixed costs</span>
                                    <span class="tabular-nums text-red-600">− R {{ number_format($fixedCosts, 2) }}</span>
                                </div>
                                @foreach($fixedRows as $expense)
                                    <div class="flex justify-between gap-3 text-[11px] text-stone-500">
                                        <span class="min-w-0 truncate">{{ $expense->description }}</span>
                                        <span class="shrink-0 tabular-nums">R {{ number_format((float) $expense->amount, 2) }}</span>
                                    </div>
                                @endforeach
                            </div>
                            <div class="space-y-1">
                                <div class="flex justify-between text-xs font-medium text-stone-600">
                                    <span>Per-shooter costs</span>
                                    <span class="tabular-nums text-red-600">− R {{ number_format($variableCosts, 2) }}</span>
                                </div>
                                @foreach($variableRows as $expense)
                                    <div class="flex justify-between gap-3 text-[11px] text-stone-500">
                                        <span class="min-w-0 truncate">{{ $expense->description }}</span>
                                        <span class="shrink-0 tabular-nums">R {{ number_format((float) $expense->amount, 2) }} × {{ $registeredShooters }}</span>
                                    </div>
                                @endforeach
                            </div>
                            <div class="flex justify-between border-t border-emerald-200 pt-2 font-bold">
                                <span class="text-stone-900">Left after costs</span>
                                <span class="tabular-nums {{ $leftAfterCosts >= 0 ? 'text-emerald-700' : 'text-red-600' }}">R {{ number_format($leftAfterCosts, 2) }}</span>
                            </div>
                        @endif
                    </div>
                </div>
            </div>
        @else
            <div class="space-y-1">
                <span class="block text-xs font-medium text-stone-500">Expected Shooters</span>
                <p class="text-sm font-semibold text-stone-900">
                    <span x-text="shooters > 0 ? shooters : '—'"></span>
                </p>
                <p class="text-[11px] text-stone-400">Uses the <strong>Estimated Shooters</strong> value entered above.</p>
            </div>

            <template x-if="fee > 0">
                <div class="space-y-3">
                    <div class="rounded-lg bg-stone-50 border border-stone-200 p-4 space-y-2 text-sm">
                        <div class="flex justify-between">
                            <span class="text-stone-600">Base entry fee</span>
                            <span class="font-semibold text-stone-900">R <span x-text="fmt(fee)"></span></span>
                        </div>
                        <template x-if="supportsClubEntry">
                            {{-- Club and provincial matches advertise the club price,
                                 not the non-member surcharge price. --}}
                            <div class="flex justify-between">
                                <span class="text-stone-500">Club entry fee</span>
                                <span class="text-stone-700">R <span x-text="fmt(clubFee)"></span></span>
                            </div>
                        </template>
                        <template x-if="! supportsClubEntry">
                            <div class="flex justify-between">
                                <span class="text-stone-500">Non-member fee</span>
                                <span class="text-stone-700">R <span x-text="fmt(fee + nmSurcharge)"></span></span>
                            </div>
                        </template>
                        <div class="flex justify-between">
                            <span class="text-stone-500">Lapsed member fee</span>
                            <span class="text-stone-700">R <span x-text="fmt(fee + lmSurcharge)"></span></span>
                        </div>

                        <hr class="border-stone-200 my-1">

                        <div class="flex justify-between text-xs text-stone-400">
                            <span>SAPRF fee (<span x-text="saprfType === 'fixed' ? 'R ' + fmt(saprfValue) + ' / shooter' : saprfValue + '%'"></span>)</span>
                            <span class="text-red-500">- R <span x-text="fmt(saprfFee)"></span></span>
                        </div>
                        <div class="flex justify-between text-xs text-stone-400">
                            <span>Platform fee (<span x-text="platformType === 'fixed' ? 'R ' + fmt(platformValue) + ' / shooter' : platformValue + '%'"></span>)</span>
                            <span class="text-red-500">- R <span x-text="fmt(platformFee)"></span></span>
                        </div>
                        <div class="flex justify-between text-xs text-stone-400">
                            <span>Est. gateway fee (~<span x-text="gatewayPct"></span>% + R<span x-text="gatewayFlat"></span>)</span>
                            <span class="text-red-500">- R <span x-text="fmt(gatewayFee)"></span></span>
                        </div>

                        <hr class="border-stone-200 my-1">

                        <div class="flex justify-between font-semibold">
                            <span class="text-stone-700">Net to Match Director <span class="text-xs font-normal text-stone-400">(member rate)</span></span>
                            <span class="text-emerald-700">R <span x-text="fmt(netMd)"></span></span>
                        </div>

                        <template x-if="supportsClubEntry">
                            <div class="mt-2 rounded-md border border-stone-200 bg-white p-3 text-xs text-stone-500 space-y-1">
                                <p class="font-semibold text-stone-700">Club entry (per shooter)</p>
                                <p>Online payment: SAPRF <span x-text="clubSaprfPct"></span>% + platform <span x-text="clubPlatformPct"></span>% + gateway at cost. MD net on R<span x-text="fmt(clubFee)"></span> = <span class="text-emerald-700 font-semibold">R <span x-text="fmt(clubNetOnline)"></span></span>.</p>
                                <p>You collect the money: SAPRF, platform, and gateway are all zero. MD keeps the full <span class="text-emerald-700 font-semibold">R <span x-text="fmt(clubFee)"></span></span>.</p>
                            </div>
                        </template>
                    </div>

                    <template x-if="shooters > 0">
                        <div class="rounded-lg bg-emerald-50 border border-emerald-200 p-4 space-y-2 text-sm">
                            <div class="flex items-center justify-between">
                                <span class="font-semibold text-emerald-800">Projected Revenue (<span x-text="shooters"></span> shooters)</span>
                            </div>
                            <div class="flex justify-between">
                                <span class="text-emerald-700">Total collected</span>
                                <span class="font-semibold text-emerald-900">R <span x-text="fmt(fee * shooters)"></span></span>
                            </div>
                            <div class="flex justify-between">
                                <span class="text-emerald-700">Total to SAPRF</span>
                                <span class="text-red-600">R <span x-text="fmt(saprfFee * shooters)"></span></span>
                            </div>
                            <div class="flex justify-between">
                                <span class="text-emerald-700">Total gateway fees</span>
                                <span class="text-red-600">R <span x-text="fmt(gatewayFee * shooters)"></span></span>
                            </div>
                            <hr class="border-emerald-200">
                            <div class="flex justify-between font-bold">
                                <span class="text-emerald-800">Net to MD</span>
                                <span class="text-emerald-900">R <span x-text="fmt(netMd * shooters)"></span></span>
                            </div>
                        </div>
                    </template>
                </div>
            </template>

            <template x-if="fee <= 0">
                <p class="text-xs text-stone-400">Enter a match entry fee above to see the cost breakdown.</p>
            </template>
        @endif
    </div>
</div>

<script>
    function costEstimator(config) {
        return {
            standalone: !!config.standalone,
            saprfType: config.saprfType,
            saprfValue: config.saprfValue,
            platformType: config.platformType,
            platformValue: config.platformValue,
            gatewayPct: config.gatewayPct,
            gatewayFlat: config.gatewayFlat,
            nmSurcharge: config.nmSurcharge,
            lmSurcharge: config.lmSurcharge,
            supportsClubEntry: !!config.supportsClubEntry,
            clubEntryFee: config.clubEntryFee ?? null,
            clubSaprfPct: config.clubSaprfPct || 0,
            clubPlatformPct: config.clubPlatformPct || 0,
            fixedCosts: config.fixedCosts || 0,
            variableUnit: config.variableUnit || 0,
            shooters: config.initialShooters > 0 ? config.initialShooters : (config.standalone ? null : 30),
            fee: config.initialFee || 0,
            saprfFee: 0,
            platformFee: 0,
            gatewayFee: 0,
            netMd: 0,
            clubFee: 0,
            clubNetOnline: 0,
            calculate() {
                if (!this.standalone) {
                    const el = document.getElementById('active_member_fee');
                    this.fee = el ? parseFloat(el.value) || 0 : 0;
                    // Club entry fee lives on its own input and falls back to
                    // the member fee when blank. Only present on club /
                    // provincial matches, so guard the lookup.
                    if (this.supportsClubEntry) {
                        const clubEl = document.getElementById('club_entry_fee');
                        const raw = clubEl ? parseFloat(clubEl.value) : NaN;
                        this.clubFee = !isNaN(raw) && raw > 0 ? raw : this.fee;
                    }
                } else if (this.supportsClubEntry) {
                    this.clubFee = this.clubEntryFee !== null ? this.clubEntryFee : this.fee;
                }
                this.saprfFee = this.saprfType === 'fixed' ? this.saprfValue : this.fee * (this.saprfValue / 100);
                this.platformFee = this.platformType === 'fixed' ? this.platformValue : this.fee * (this.platformValue / 100);
                this.gatewayFee = this.fee > 0 ? this.fee * (this.gatewayPct / 100) + this.gatewayFlat : 0;
                this.netMd = this.fee - this.saprfFee - this.platformFee - this.gatewayFee;
                if (this.netMd < 0) this.netMd = 0;

                if (this.supportsClubEntry) {
                    const base = this.clubFee || 0;
                    const clubSaprf = base * (this.clubSaprfPct / 100);
                    const clubPlatform = base * (this.clubPlatformPct / 100);
                    const clubGateway = base > 0 ? base * (this.gatewayPct / 100) + this.gatewayFlat : 0;
                    this.clubNetOnline = Math.max(0, base - clubSaprf - clubPlatform - clubGateway);
                }
            },
            estimatedLeft() {
                const count = parseInt(this.shooters, 10) || 0;
                return (this.netMd * count) - this.fixedCosts - (this.variableUnit * count);
            },
            fmt(v) { return (Number(v) || 0).toFixed(2); }
        }
    }
</script>
