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
            class="rounded-xl border border-stone-200 bg-white shadow-sm p-8 space-y-6 max-w-2xl">
            @csrf

            <div class="grid sm:grid-cols-2 gap-6">
                <div class="sm:col-span-2">
                    <label for="nickname" class="block text-sm font-medium text-stone-700 mb-1">Nickname</label>
                    <input type="text" name="nickname" id="nickname" value="{{ old('nickname') }}" required placeholder="e.g. Competition .308"
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
                    />
                </div>

                <div>
                    <label for="action_description" class="block text-sm font-medium text-stone-700 mb-1">Action</label>
                    <input type="text" name="action_description" id="action_description" value="{{ old('action_description') }}" placeholder="e.g. Bighorn TL3, Defiance Tenacity"
                        class="w-full rounded-lg border-stone-300 text-sm py-2.5 px-3 focus:ring-emerald-500 focus:border-emerald-500">
                </div>

                <div>
                    <label for="barrel_description" class="block text-sm font-medium text-stone-700 mb-1">Barrel</label>
                    <input type="text" name="barrel_description" id="barrel_description" value="{{ old('barrel_description') }}" placeholder="e.g. Bartlein 6.5mm, Krieger"
                        class="w-full rounded-lg border-stone-300 text-sm py-2.5 px-3 focus:ring-emerald-500 focus:border-emerald-500">
                </div>

                <div>
                    <label for="chassis_description" class="block text-sm font-medium text-stone-700 mb-1">Chassis / Stock</label>
                    <input type="text" name="chassis_description" id="chassis_description" value="{{ old('chassis_description') }}" placeholder="e.g. MDT ACC Elite"
                        class="w-full rounded-lg border-stone-300 text-sm py-2.5 px-3 focus:ring-emerald-500 focus:border-emerald-500">
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
                    />
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
