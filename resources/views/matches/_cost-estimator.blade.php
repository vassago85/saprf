@php
    $settings = app(\App\Services\SettingsService::class);
    $saprfPct = (float) $settings->get('saprf_fee_percentage', 5);
    $platformPct = (float) $settings->get('platform_fee_percentage', 5);
    $gatewayPct = (float) $settings->get('estimated_gateway_fee_percentage', 3.5);
    $gatewayFlat = (float) $settings->get('estimated_gateway_flat_fee', 2);
    $nmSurcharge = (float) $settings->get('non_member_surcharge', 0);
    $lmSurcharge = (float) $settings->get('lapsed_member_surcharge', 0);
@endphp

<div class="sm:col-span-2"
     x-data="costEstimator({
         saprfPct: {{ $saprfPct }},
         platformPct: {{ $platformPct }},
         gatewayPct: {{ $gatewayPct }},
         gatewayFlat: {{ $gatewayFlat }},
         nmSurcharge: {{ $nmSurcharge }},
         lmSurcharge: {{ $lmSurcharge }},
     })"
     x-init="$nextTick(() => { calculate(); const el = document.getElementById('active_member_fee'); if(el) el.addEventListener('input', () => calculate()); })"
     x-effect="shooters; calculate()">

    <div class="rounded-xl border border-stone-200 bg-white shadow-sm p-5 space-y-4">
        <div class="flex items-center justify-between">
            <h3 class="text-sm font-semibold text-stone-900">Cost Estimator</h3>
            <span class="text-xs text-stone-400">Per shooter estimate</span>
        </div>

        <div class="space-y-2">
            <label class="block text-xs font-medium text-stone-500">Expected Shooters</label>
            <input type="number" x-model.number="shooters" min="1" max="500" placeholder="30"
                   class="block w-32 rounded-lg border-stone-300 text-sm focus:ring-emerald-500 focus:border-emerald-500" />
        </div>

        <template x-if="fee > 0">
            <div class="space-y-3">
                <div class="rounded-lg bg-stone-50 border border-stone-200 p-4 space-y-2 text-sm">
                    <div class="flex justify-between">
                        <span class="text-stone-600">Base entry fee</span>
                        <span class="font-semibold text-stone-900">R <span x-text="fmt(fee)"></span></span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-stone-500">Non-member fee</span>
                        <span class="text-stone-700">R <span x-text="fmt(fee + nmSurcharge)"></span></span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-stone-500">Lapsed member fee</span>
                        <span class="text-stone-700">R <span x-text="fmt(fee + lmSurcharge)"></span></span>
                    </div>

                    <hr class="border-stone-200 my-1">

                    <div class="flex justify-between text-xs text-stone-400">
                        <span>SAPRF fee (<span x-text="saprfPct"></span>%)</span>
                        <span class="text-red-500">- R <span x-text="fmt(saprfFee)"></span></span>
                    </div>
                    <div class="flex justify-between text-xs text-stone-400">
                        <span>Platform fee (<span x-text="platformPct"></span>%)</span>
                        <span class="text-red-500">- R <span x-text="fmt(platformFee)"></span></span>
                    </div>
                    <div class="flex justify-between text-xs text-stone-400">
                        <span>Est. gateway fee (~<span x-text="gatewayPct"></span>% + R<span x-text="gatewayFlat"></span>)</span>
                        <span class="text-red-500">- R <span x-text="fmt(gatewayFee)"></span></span>
                    </div>

                    <hr class="border-stone-200 my-1">

                    <div class="flex justify-between font-semibold">
                        <span class="text-stone-700">Net to Match Director</span>
                        <span class="text-emerald-700">R <span x-text="fmt(netMd)"></span></span>
                    </div>
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
    </div>
</div>

<script>
    function costEstimator(config) {
        return {
            saprfPct: config.saprfPct,
            platformPct: config.platformPct,
            gatewayPct: config.gatewayPct,
            gatewayFlat: config.gatewayFlat,
            nmSurcharge: config.nmSurcharge,
            lmSurcharge: config.lmSurcharge,
            shooters: 30,
            fee: 0,
            saprfFee: 0,
            platformFee: 0,
            gatewayFee: 0,
            netMd: 0,
            calculate() {
                const el = document.getElementById('active_member_fee');
                this.fee = el ? parseFloat(el.value) || 0 : 0;
                this.saprfFee = this.fee * (this.saprfPct / 100);
                this.platformFee = this.fee * (this.platformPct / 100);
                this.gatewayFee = this.fee > 0 ? this.fee * (this.gatewayPct / 100) + this.gatewayFlat : 0;
                this.netMd = this.fee - this.saprfFee - this.platformFee - this.gatewayFee;
                if (this.netMd < 0) this.netMd = 0;
            },
            fmt(v) { return v.toFixed(2); }
        }
    }
</script>
