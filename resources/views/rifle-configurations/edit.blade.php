<x-layouts.app :title="'Edit Rifle - SAPRF'">
    <div class="space-y-6">
        <div class="flex items-center justify-between">
            <div>
                <h1 class="font-heading text-3xl font-bold text-stone-900 tracking-tight">Edit Rifle</h1>
                <p class="mt-1 text-sm text-stone-500">Update {{ $rifleConfiguration->nickname }} configuration.</p>
            </div>
            <a href="{{ route('rifle-configurations.show', $rifleConfiguration) }}" class="text-sm text-emerald-700 font-medium hover:text-emerald-800">&larr; Back to Rifle</a>
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

        <form method="POST" action="{{ route('rifle-configurations.update', $rifleConfiguration) }}"
            class="rounded-xl border border-stone-200 bg-white shadow-sm p-8 space-y-6 max-w-2xl">
            @csrf
            @method('PUT')

            <div class="grid sm:grid-cols-2 gap-6">
                <div class="sm:col-span-2">
                    <label for="nickname" class="block text-sm font-medium text-stone-700 mb-1">Nickname</label>
                    <input type="text" name="nickname" id="nickname" value="{{ old('nickname', $rifleConfiguration->nickname) }}" required
                        class="w-full rounded-lg border-stone-300 text-sm py-2.5 px-3 focus:ring-emerald-500 focus:border-emerald-500">
                </div>

                <div>
                    <x-typeahead
                        name="firearm_make_id"
                        label="Make"
                        search-url="/api/v1/firearm-makes"
                        create-url="/api/firearm-makes"
                        placeholder="Type to search makes..."
                        display-field="name"
                        subtext-field="country"
                        :required="true"
                        :initial-id="old('firearm_make_id', $rifleConfiguration->firearm_make_id)"
                        :initial-text="$rifleConfiguration->make?->name"
                    />
                </div>

                <div>
                    <x-typeahead
                        name="firearm_model_id"
                        label="Model"
                        search-url="/api/v1/firearm-models"
                        create-url="/api/firearm-models"
                        placeholder="Type to search models..."
                        display-field="name"
                        depends-on="firearm_make_id"
                        depends-param="make_id"
                        :create-payload-extra="true"
                        :initial-id="old('firearm_model_id', $rifleConfiguration->firearm_model_id)"
                        :initial-text="$rifleConfiguration->model?->name"
                    />
                </div>

                <div>
                    <x-typeahead
                        name="firearm_calibre_id"
                        label="Calibre"
                        search-url="/api/v1/firearm-calibres"
                        create-url="/api/firearm-calibres"
                        placeholder="Type to search calibres..."
                        display-field="name"
                        subtext-field="category"
                        :required="true"
                        :initial-id="old('firearm_calibre_id', $rifleConfiguration->firearm_calibre_id)"
                        :initial-text="$rifleConfiguration->calibre?->name"
                    />
                </div>

                <div>
                    <label for="optic_description" class="block text-sm font-medium text-stone-700 mb-1">Optic</label>
                    <input type="text" name="optic_description" id="optic_description" value="{{ old('optic_description', $rifleConfiguration->optic_description) }}"
                        placeholder="e.g. Nightforce ATACR 7-35x56"
                        class="w-full rounded-lg border-stone-300 text-sm py-2.5 px-3 focus:ring-emerald-500 focus:border-emerald-500">
                </div>

                <div>
                    <label for="bullet_description" class="block text-sm font-medium text-stone-700 mb-1">Bullet</label>
                    <input type="text" name="bullet_description" id="bullet_description" value="{{ old('bullet_description', $rifleConfiguration->bullet_description) }}"
                        placeholder="e.g. Berger 185gr Juggernaut"
                        class="w-full rounded-lg border-stone-300 text-sm py-2.5 px-3 focus:ring-emerald-500 focus:border-emerald-500">
                </div>

                <div>
                    <label for="barrel_length" class="block text-sm font-medium text-stone-700 mb-1">Barrel Length</label>
                    <input type="text" name="barrel_length" id="barrel_length" value="{{ old('barrel_length', $rifleConfiguration->barrel_length) }}"
                        placeholder='e.g. 26"'
                        class="w-full rounded-lg border-stone-300 text-sm py-2.5 px-3 focus:ring-emerald-500 focus:border-emerald-500">
                </div>

                <div>
                    <label for="twist_rate" class="block text-sm font-medium text-stone-700 mb-1">Twist Rate</label>
                    <input type="text" name="twist_rate" id="twist_rate" value="{{ old('twist_rate', $rifleConfiguration->twist_rate) }}"
                        placeholder="e.g. 1:10"
                        class="w-full rounded-lg border-stone-300 text-sm py-2.5 px-3 focus:ring-emerald-500 focus:border-emerald-500">
                </div>

                <div class="sm:col-span-2">
                    <label for="notes" class="block text-sm font-medium text-stone-700 mb-1">Notes</label>
                    <textarea name="notes" id="notes" rows="3"
                        class="w-full rounded-lg border-stone-300 text-sm py-2.5 px-3 focus:ring-emerald-500 focus:border-emerald-500">{{ old('notes', $rifleConfiguration->notes) }}</textarea>
                </div>

                <div class="sm:col-span-2">
                    <label class="flex items-center gap-3 cursor-pointer">
                        <input type="checkbox" name="is_primary" value="1"
                            @checked(old('is_primary', $rifleConfiguration->is_primary))
                            class="rounded border-stone-300 text-emerald-600 focus:ring-emerald-500">
                        <span class="text-sm font-medium text-stone-700">Set as primary rifle</span>
                    </label>
                </div>
            </div>

            <div class="flex items-center gap-4 pt-2">
                <button type="submit"
                    class="rounded-xl bg-emerald-700 px-6 py-2.5 text-sm font-semibold text-white hover:bg-emerald-800 transition">
                    Update Rifle
                </button>
                <a href="{{ route('rifle-configurations.show', $rifleConfiguration) }}" class="text-sm text-stone-500 hover:text-stone-700">Cancel</a>
            </div>
        </form>
    </div>
</x-layouts.app>
