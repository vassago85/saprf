<x-layouts.app :title="'Add Rifle - SAPRF'">
    <div class="space-y-6">
        <div class="flex items-center justify-between">
            <div>
                <h1 class="font-heading text-3xl font-bold text-stone-900 tracking-tight">Add Rifle</h1>
                <p class="mt-1 text-sm text-stone-500">Build up your rifle configuration profile.</p>
            </div>
            <a href="{{ route('rifle-configurations.index') }}" class="text-sm text-emerald-700 font-medium hover:text-emerald-800">&larr; Back to Rifles</a>
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

        <form method="POST" action="{{ route('rifle-configurations.store') }}"
            x-data="{
                makeId: '{{ old('firearm_make_id', '') }}',
                models: [],
                modelId: '{{ old('firearm_model_id', '') }}',
                async loadModels() {
                    if (!this.makeId) { this.models = []; this.modelId = ''; return; }
                    const res = await fetch('/api/v1/firearm-models?make_id=' + this.makeId);
                    const data = await res.json();
                    this.models = data.data || [];
                    this.modelId = '';
                }
            }"
            x-init="if (makeId) { await loadModels(); modelId = '{{ old('firearm_model_id', '') }}'; }"
            class="rounded-xl border border-stone-200 bg-white shadow-sm p-8 space-y-6 max-w-2xl">
            @csrf

            <div class="grid sm:grid-cols-2 gap-6">
                <div class="sm:col-span-2">
                    <label for="nickname" class="block text-sm font-medium text-stone-700 mb-1">Nickname</label>
                    <input type="text" name="nickname" id="nickname" value="{{ old('nickname') }}" required placeholder="e.g. Competition .308"
                        class="w-full rounded-lg border-stone-300 text-sm py-2.5 px-3 focus:ring-emerald-500 focus:border-emerald-500">
                </div>

                <div>
                    <label for="firearm_make_id" class="block text-sm font-medium text-stone-700 mb-1">Make</label>
                    <select name="firearm_make_id" id="firearm_make_id" required
                        x-model="makeId" @change="loadModels()"
                        class="w-full rounded-lg border-stone-300 text-sm py-2.5 px-3 focus:ring-emerald-500 focus:border-emerald-500">
                        <option value="">Select make...</option>
                        @foreach ($makes as $make)
                            <option value="{{ $make->id }}">{{ $make->name }}</option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label for="firearm_model_id" class="block text-sm font-medium text-stone-700 mb-1">Model</label>
                    <select name="firearm_model_id" id="firearm_model_id"
                        x-model="modelId"
                        :disabled="models.length === 0"
                        class="w-full rounded-lg border-stone-300 text-sm py-2.5 px-3 focus:ring-emerald-500 focus:border-emerald-500 disabled:bg-stone-50 disabled:text-stone-400">
                        <option value="">Select model...</option>
                        <template x-for="model in models" :key="model.id">
                            <option :value="model.id" x-text="model.name"></option>
                        </template>
                    </select>
                </div>

                <div>
                    <label for="calibre_id" class="block text-sm font-medium text-stone-700 mb-1">Calibre</label>
                    <select name="calibre_id" id="calibre_id" required
                        class="w-full rounded-lg border-stone-300 text-sm py-2.5 px-3 focus:ring-emerald-500 focus:border-emerald-500">
                        <option value="">Select calibre...</option>
                        @foreach ($calibres as $calibre)
                            <option value="{{ $calibre->id }}" @selected(old('calibre_id') == $calibre->id)>{{ $calibre->name }}</option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label for="optic_description" class="block text-sm font-medium text-stone-700 mb-1">Optic</label>
                    <input type="text" name="optic_description" id="optic_description" value="{{ old('optic_description') }}" placeholder="e.g. Nightforce ATACR 7-35x56"
                        class="w-full rounded-lg border-stone-300 text-sm py-2.5 px-3 focus:ring-emerald-500 focus:border-emerald-500">
                </div>

                <div>
                    <label for="bullet_description" class="block text-sm font-medium text-stone-700 mb-1">Bullet</label>
                    <input type="text" name="bullet_description" id="bullet_description" value="{{ old('bullet_description') }}" placeholder="e.g. Berger 185gr Juggernaut"
                        class="w-full rounded-lg border-stone-300 text-sm py-2.5 px-3 focus:ring-emerald-500 focus:border-emerald-500">
                </div>

                <div>
                    <label for="barrel_length" class="block text-sm font-medium text-stone-700 mb-1">Barrel Length</label>
                    <input type="text" name="barrel_length" id="barrel_length" value="{{ old('barrel_length') }}" placeholder='e.g. 26"'
                        class="w-full rounded-lg border-stone-300 text-sm py-2.5 px-3 focus:ring-emerald-500 focus:border-emerald-500">
                </div>

                <div>
                    <label for="twist_rate" class="block text-sm font-medium text-stone-700 mb-1">Twist Rate</label>
                    <input type="text" name="twist_rate" id="twist_rate" value="{{ old('twist_rate') }}" placeholder="e.g. 1:10"
                        class="w-full rounded-lg border-stone-300 text-sm py-2.5 px-3 focus:ring-emerald-500 focus:border-emerald-500">
                </div>

                <div class="sm:col-span-2">
                    <label for="notes" class="block text-sm font-medium text-stone-700 mb-1">Notes</label>
                    <textarea name="notes" id="notes" rows="3" placeholder="Any additional notes about this build..."
                        class="w-full rounded-lg border-stone-300 text-sm py-2.5 px-3 focus:ring-emerald-500 focus:border-emerald-500">{{ old('notes') }}</textarea>
                </div>

                <div class="sm:col-span-2">
                    <label class="flex items-center gap-3 cursor-pointer">
                        <input type="checkbox" name="is_primary" value="1"
                            @checked(old('is_primary'))
                            class="rounded border-stone-300 text-emerald-600 focus:ring-emerald-500">
                        <span class="text-sm font-medium text-stone-700">Set as primary rifle</span>
                    </label>
                </div>
            </div>

            <div class="flex items-center gap-4 pt-2">
                <button type="submit"
                    class="rounded-xl bg-emerald-700 px-6 py-2.5 text-sm font-semibold text-white hover:bg-emerald-800 transition">
                    Save Rifle
                </button>
                <a href="{{ route('rifle-configurations.index') }}" class="text-sm text-stone-500 hover:text-stone-700">Cancel</a>
            </div>
        </form>
    </div>
</x-layouts.app>
