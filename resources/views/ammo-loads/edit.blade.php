<x-layouts.app :title="'Edit ' . $ammoLoad->nickname . ' - SAPRF'">
    <div class="space-y-6">
        <div class="flex items-center justify-between">
            <div>
                @if ($ammoLoad->rifleConfiguration)
                    <a href="{{ route('rifle-configurations.show', $ammoLoad->rifle_configuration_id) }}" class="text-sm text-emerald-700 font-medium hover:text-emerald-800">&larr; Back to {{ $ammoLoad->rifleConfiguration->nickname }}</a>
                @else
                    <a href="{{ route('ammo-loads.index') }}" class="text-sm text-emerald-700 font-medium hover:text-emerald-800">&larr; Back</a>
                @endif
                <h1 class="mt-1 font-heading text-3xl font-bold text-stone-900 tracking-tight">Edit Ammo Load</h1>
                <p class="mt-1 text-sm text-stone-500">{{ $ammoLoad->nickname }}</p>
            </div>
        </div>

        @if ($errors->any())
            <div class="rounded-xl border border-red-200 bg-red-50 p-4">
                <ul class="list-disc list-inside text-sm text-red-700 space-y-1">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <form method="POST" action="{{ route('ammo-loads.update', $ammoLoad) }}"
            class="rounded-xl border border-stone-200 bg-white shadow-sm p-8 space-y-8 max-w-2xl">
            @csrf
            @method('PUT')

            {{-- Load Name --}}
            <div>
                <label for="nickname" class="block text-sm font-medium text-stone-700 mb-1">Load Name</label>
                <input type="text" name="nickname" id="nickname" value="{{ old('nickname', $ammoLoad->nickname) }}" required
                    class="w-full rounded-lg border-stone-300 text-sm py-2.5 px-3 focus:ring-emerald-500 focus:border-emerald-500">
            </div>

            {{-- Bullet --}}
            <div class="space-y-4">
                <h3 class="text-sm font-semibold text-stone-800 uppercase tracking-wider">Bullet</h3>
                <div class="grid sm:grid-cols-2 gap-6">
                    <div>
                        <label for="bullet_make" class="block text-xs font-medium text-stone-500 mb-1">Make</label>
                        <input type="text" name="bullet_make" id="bullet_make" value="{{ old('bullet_make', $ammoLoad->bullet_make) }}"
                            placeholder="e.g. Berger, Sierra, Hornady"
                            class="w-full rounded-lg border-stone-300 text-sm py-2.5 px-3 focus:ring-emerald-500 focus:border-emerald-500">
                    </div>
                    <div>
                        <label for="bullet_model" class="block text-xs font-medium text-stone-500 mb-1">Model</label>
                        <input type="text" name="bullet_model" id="bullet_model" value="{{ old('bullet_model', $ammoLoad->bullet_model) }}"
                            placeholder="e.g. Juggernaut, MatchKing, ELD-M"
                            class="w-full rounded-lg border-stone-300 text-sm py-2.5 px-3 focus:ring-emerald-500 focus:border-emerald-500">
                    </div>
                    <div>
                        <label for="bullet_weight" class="block text-xs font-medium text-stone-500 mb-1">Weight</label>
                        <input type="text" name="bullet_weight" id="bullet_weight" value="{{ old('bullet_weight', $ammoLoad->bullet_weight) }}"
                            placeholder="e.g. 140gr, 185gr, 77gr"
                            class="w-full rounded-lg border-stone-300 text-sm py-2.5 px-3 focus:ring-emerald-500 focus:border-emerald-500">
                    </div>
                    <div>
                        <label for="bullet_type" class="block text-xs font-medium text-stone-500 mb-1">Type</label>
                        <input type="text" name="bullet_type" id="bullet_type" value="{{ old('bullet_type', $ammoLoad->bullet_type) }}"
                            placeholder="e.g. HPBT, OTM, Hybrid Target"
                            class="w-full rounded-lg border-stone-300 text-sm py-2.5 px-3 focus:ring-emerald-500 focus:border-emerald-500">
                    </div>
                </div>
            </div>

            {{-- Brass & Primer --}}
            <div class="space-y-4">
                <h3 class="text-sm font-semibold text-stone-800 uppercase tracking-wider">Brass &amp; Primer</h3>
                <div class="grid sm:grid-cols-2 gap-6">
                    <div>
                        <label for="brass" class="block text-xs font-medium text-stone-500 mb-1">Brass</label>
                        <input type="text" name="brass" id="brass" value="{{ old('brass', $ammoLoad->brass) }}"
                            placeholder="e.g. Lapua, Peterson, ADG"
                            class="w-full rounded-lg border-stone-300 text-sm py-2.5 px-3 focus:ring-emerald-500 focus:border-emerald-500">
                    </div>
                    <div>
                        <label for="primer" class="block text-xs font-medium text-stone-500 mb-1">Primer</label>
                        <input type="text" name="primer" id="primer" value="{{ old('primer', $ammoLoad->primer) }}"
                            placeholder="e.g. CCI BR2, Federal 210M"
                            class="w-full rounded-lg border-stone-300 text-sm py-2.5 px-3 focus:ring-emerald-500 focus:border-emerald-500">
                    </div>
                </div>
            </div>

            {{-- Powder & Charge --}}
            <div class="space-y-4">
                <h3 class="text-sm font-semibold text-stone-800 uppercase tracking-wider">Powder &amp; Charge</h3>
                <div class="grid sm:grid-cols-2 gap-6">
                    <div>
                        <label for="powder" class="block text-xs font-medium text-stone-500 mb-1">Powder</label>
                        <input type="text" name="powder" id="powder" value="{{ old('powder', $ammoLoad->powder) }}"
                            placeholder="e.g. Vihtavuori N150, Hodgdon H4350"
                            class="w-full rounded-lg border-stone-300 text-sm py-2.5 px-3 focus:ring-emerald-500 focus:border-emerald-500">
                    </div>
                    <div>
                        <label for="charge_weight" class="block text-xs font-medium text-stone-500 mb-1">Charge Weight</label>
                        <input type="text" name="charge_weight" id="charge_weight" value="{{ old('charge_weight', $ammoLoad->charge_weight) }}"
                            placeholder="e.g. 42.5gr"
                            class="w-full rounded-lg border-stone-300 text-sm py-2.5 px-3 focus:ring-emerald-500 focus:border-emerald-500">
                    </div>
                </div>
            </div>

            {{-- Cartridge & Velocity --}}
            <div class="space-y-4">
                <h3 class="text-sm font-semibold text-stone-800 uppercase tracking-wider">Cartridge &amp; Velocity</h3>
                <div class="grid sm:grid-cols-3 gap-6">
                    <div>
                        <label for="coal" class="block text-xs font-medium text-stone-500 mb-1">COAL</label>
                        <input type="text" name="coal" id="coal" value="{{ old('coal', $ammoLoad->coal) }}"
                            placeholder='e.g. 2.810"'
                            class="w-full rounded-lg border-stone-300 text-sm py-2.5 px-3 focus:ring-emerald-500 focus:border-emerald-500">
                    </div>
                    <div>
                        <label for="cbto" class="block text-xs font-medium text-stone-500 mb-1">CBTO</label>
                        <input type="text" name="cbto" id="cbto" value="{{ old('cbto', $ammoLoad->cbto) }}"
                            placeholder='e.g. 2.150"'
                            class="w-full rounded-lg border-stone-300 text-sm py-2.5 px-3 focus:ring-emerald-500 focus:border-emerald-500">
                    </div>
                    <div>
                        <label for="velocity" class="block text-xs font-medium text-stone-500 mb-1">Velocity</label>
                        <input type="text" name="velocity" id="velocity" value="{{ old('velocity', $ammoLoad->velocity) }}"
                            placeholder="e.g. 2750fps"
                            class="w-full rounded-lg border-stone-300 text-sm py-2.5 px-3 focus:ring-emerald-500 focus:border-emerald-500">
                    </div>
                </div>
            </div>

            {{-- Notes --}}
            <div>
                <label for="notes" class="block text-sm font-medium text-stone-700 mb-1">Notes</label>
                <textarea name="notes" id="notes" rows="3"
                    class="w-full rounded-lg border-stone-300 text-sm py-2.5 px-3 focus:ring-emerald-500 focus:border-emerald-500">{{ old('notes', $ammoLoad->notes) }}</textarea>
            </div>

            <div class="flex items-center gap-4 pt-2">
                <button type="submit"
                    class="rounded-xl bg-emerald-700 px-6 py-2.5 text-sm font-semibold text-white hover:bg-emerald-800 transition">
                    Update Ammo Load
                </button>
                @if ($ammoLoad->rifleConfiguration)
                    <a href="{{ route('rifle-configurations.show', $ammoLoad->rifle_configuration_id) }}" class="text-sm text-stone-500 hover:text-stone-700">Cancel</a>
                @else
                    <a href="{{ route('ammo-loads.index') }}" class="text-sm text-stone-500 hover:text-stone-700">Cancel</a>
                @endif
            </div>
        </form>
    </div>
</x-layouts.app>
