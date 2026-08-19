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
                        class="w-full rounded-lg border border-stone-300 text-sm py-2.5 px-3 focus:ring-emerald-500 focus:border-emerald-500">
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
                    <label for="action_description" class="block text-sm font-medium text-stone-700 mb-1">Action</label>
                    <input type="text" name="action_description" id="action_description" value="{{ old('action_description', $rifleConfiguration->action_description) }}"
                        placeholder="e.g. Bighorn TL3, Defiance Tenacity"
                        class="w-full rounded-lg border border-stone-300 text-sm py-2.5 px-3 focus:ring-emerald-500 focus:border-emerald-500">
                </div>

                <div>
                    <label for="barrel_description" class="block text-sm font-medium text-stone-700 mb-1">Barrel</label>
                    <input type="text" name="barrel_description" id="barrel_description" value="{{ old('barrel_description', $rifleConfiguration->barrel_description) }}"
                        placeholder="e.g. Bartlein 6.5mm, Krieger"
                        class="w-full rounded-lg border border-stone-300 text-sm py-2.5 px-3 focus:ring-emerald-500 focus:border-emerald-500">
                </div>

                <div>
                    <label for="chassis_description" class="block text-sm font-medium text-stone-700 mb-1">Chassis / Stock</label>
                    <input type="text" name="chassis_description" id="chassis_description" value="{{ old('chassis_description', $rifleConfiguration->chassis_description) }}"
                        placeholder="e.g. MDT ACC Elite"
                        class="w-full rounded-lg border border-stone-300 text-sm py-2.5 px-3 focus:ring-emerald-500 focus:border-emerald-500">
                </div>

                <div>
                    <x-typeahead
                        name="optic_make_id"
                        label="Optic Brand"
                        search-url="/api/v1/optic-makes"
                        create-url="/api/optic-makes"
                        placeholder="Type to search brands..."
                        display-field="name"
                        subtext-field="country"
                        :initial-id="old('optic_make_id', $rifleConfiguration->optic_make_id)"
                        :initial-text="$rifleConfiguration->opticMake?->name"
                    />
                </div>

                <div>
                    <x-typeahead
                        name="optic_model_id"
                        label="Optic Model"
                        search-url="/api/v1/optic-models"
                        create-url="/api/optic-models"
                        placeholder="Type to search models..."
                        display-field="name"
                        depends-on="optic_make_id"
                        depends-param="make_id"
                        :create-payload-extra="true"
                        :initial-id="old('optic_model_id', $rifleConfiguration->optic_model_id)"
                        :initial-text="$rifleConfiguration->opticModel?->name"
                    />
                </div>

                <div>
                    <label for="barrel_length" class="block text-sm font-medium text-stone-700 mb-1">Barrel Length</label>
                    <input type="text" name="barrel_length" id="barrel_length" value="{{ old('barrel_length', $rifleConfiguration->barrel_length) }}"
                        placeholder='e.g. 26"'
                        class="w-full rounded-lg border border-stone-300 text-sm py-2.5 px-3 focus:ring-emerald-500 focus:border-emerald-500">
                </div>

                <div>
                    <label for="twist_rate" class="block text-sm font-medium text-stone-700 mb-1">Twist Rate</label>
                    <input type="text" name="twist_rate" id="twist_rate" value="{{ old('twist_rate', $rifleConfiguration->twist_rate) }}"
                        placeholder="e.g. 1:10"
                        class="w-full rounded-lg border border-stone-300 text-sm py-2.5 px-3 focus:ring-emerald-500 focus:border-emerald-500">
                </div>

                <div class="sm:col-span-2">
                    <label for="notes" class="block text-sm font-medium text-stone-700 mb-1">Notes</label>
                    <textarea name="notes" id="notes" rows="3"
                        class="w-full rounded-lg border border-stone-300 text-sm py-2.5 px-3 focus:ring-emerald-500 focus:border-emerald-500">{{ old('notes', $rifleConfiguration->notes) }}</textarea>
                </div>

                <x-rifle-primary-series-field
                    :current="$rifleConfiguration->primary_series"
                    :show-on-profile="$rifleConfiguration->show_on_profile"
                />
            </div>

            <div class="flex items-center gap-4 pt-2">
                <button type="submit"
                    class="rounded-xl bg-emerald-700 px-6 py-2.5 text-sm font-semibold text-white hover:bg-emerald-800 transition">
                    Update Rifle
                </button>
                <a href="{{ route('rifle-configurations.show', $rifleConfiguration) }}" class="text-sm text-stone-500 hover:text-stone-700">Cancel</a>
            </div>
        </form>

        {{-- Danger zone --}}
        <div class="rounded-xl border border-red-200 bg-red-50 p-6 max-w-2xl">
            <h2 class="font-heading text-sm font-semibold uppercase tracking-wider text-red-700 mb-2">Danger Zone</h2>
            <p class="text-sm text-red-700">Removing this rifle archives it — historical registrations and ammo loads stay intact. You can re-add it later.</p>
            <form method="POST" action="{{ route('rifle-configurations.destroy', $rifleConfiguration) }}" class="mt-3"
                onsubmit="return confirm('Remove rifle &quot;{{ addslashes($rifleConfiguration->nickname) }}&quot;?\n\nThis will archive the rifle (it stays linked to any historical registrations and ammo loads). You can re-add it later if you like.\n\nProceed?')">
                @csrf
                @method('DELETE')
                <button type="submit"
                    class="inline-flex items-center gap-2 rounded-xl border border-red-300 bg-white px-4 py-2.5 text-sm font-semibold text-red-700 hover:bg-red-100 transition">
                    <svg class="size-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m14.74 9-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0"/></svg>
                    Delete Rifle
                </button>
            </form>
        </div>
    </div>
</x-layouts.app>
