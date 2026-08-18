<x-layouts.app :title="$list ? 'Edit saved list' : 'New saved list'">
    @php
        // Same map as the announcement composer — keep them in sync when
        // adding new audience types. If this duplication becomes painful,
        // extract to a shared Blade component.
        $audienceValueInputs = [
            'all' => [],
            'active_members' => [],
            'membership_type' => [['name' => 'membership_type', 'label' => 'Membership type', 'options' => ['paid' => 'Paid', 'free' => 'Free']]],
            'fee_tier' => [['name' => 'fee_tier_id', 'label' => 'Fee tier', 'options' => $feeTiers->pluck('name', 'id')->all()]],
            'division' => [['name' => 'division_id', 'label' => 'Division', 'options' => $divisions->pluck('name', 'id')->all()]],
            'series' => [
                ['name' => 'series', 'label' => 'Series', 'options' => ['PRS' => 'PRS (Centerfire)', 'PR22' => 'PR22 (Rimfire)']],
                ['name' => 'season', 'label' => 'Season', 'options' => [(string) now()->year => (string) now()->year, (string) (now()->year - 1) => (string) (now()->year - 1)]],
            ],
            'role' => [['name' => 'role', 'label' => 'Role', 'options' => collect($roles)->mapWithKeys(fn ($r) => [$r => ucwords(str_replace('_', ' ', $r))])->all()]],
            'club' => [['name' => 'club_id', 'label' => 'Club', 'options' => $clubs->pluck('name', 'id')->all()]],
            'province' => [['name' => 'province_id', 'label' => 'Province', 'options' => $provinces->pluck('name', 'id')->all()]],
            'individual' => [['name' => 'user_ids', 'label' => 'User IDs (comma-separated)', 'text' => true]],
            'saved_list' => [['name' => 'list_id', 'label' => 'Saved list', 'options' => $savedLists->pluck('name', 'id')->all()]],
        ];

        $existingRules = old('rules', $list?->rules ?? [['mode' => 'include', 'type' => 'active_members', 'value' => new \stdClass()]]);
    @endphp

    <div class="max-w-4xl space-y-6"
        x-data="savedListForm(@js([
            'previewUrl' => route('saved-lists.preview'),
            'csrf' => csrf_token(),
            'audienceInputs' => $audienceValueInputs,
            'initialRules' => $existingRules,
        ]))">
        <div class="flex items-center justify-between">
            <div>
                <h1 class="font-heading text-3xl font-bold text-stone-900">
                    {{ $list ? 'Edit saved list' : 'New saved list' }}
                </h1>
                <p class="mt-1 text-sm text-stone-500">A reusable audience recipe. Compose once, pick from the announcement composer later.</p>
            </div>
            <a href="{{ route('saved-lists.index') }}" class="text-sm font-medium text-emerald-700 hover:text-emerald-800">← Back</a>
        </div>

        @if ($errors->any())
            <div class="rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-700">
                <ul class="list-inside list-disc space-y-1">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <form method="POST"
            action="{{ $list ? route('saved-lists.update', $list) : route('saved-lists.store') }}"
            class="space-y-6">
            @csrf
            @if ($list) @method('PUT') @endif

            <div class="rounded-xl border border-stone-200 bg-white p-6 shadow-sm space-y-4">
                <div>
                    <label class="block text-sm font-medium text-stone-700">Name</label>
                    <input type="text" name="name" required maxlength="120"
                        value="{{ old('name', $list?->name) }}"
                        class="mt-1 block w-full rounded-lg border border-stone-300 px-3 py-2 text-sm">
                </div>
                <div>
                    <label class="block text-sm font-medium text-stone-700">Description (optional)</label>
                    <textarea name="description" rows="2" maxlength="500"
                        class="mt-1 block w-full rounded-lg border border-stone-300 px-3 py-2 text-sm">{{ old('description', $list?->description) }}</textarea>
                </div>
            </div>

            <div class="rounded-xl border border-stone-200 bg-white p-6 shadow-sm space-y-4">
                <div class="flex items-center justify-between">
                    <div>
                        <h2 class="font-heading text-base font-semibold text-stone-900">Rules</h2>
                        <p class="text-xs text-stone-400 mt-1">Include / exclude rules combine the same way as the composer.</p>
                    </div>
                    <button type="button" @click="addRule()"
                        class="rounded-lg bg-emerald-50 px-3 py-1.5 text-xs font-semibold text-emerald-700 hover:bg-emerald-100">
                        + Add rule
                    </button>
                </div>

                <template x-for="(rule, idx) in rules" :key="idx">
                    <div class="rounded-lg border border-stone-200 p-3 space-y-2">
                        <div class="grid grid-cols-1 sm:grid-cols-3 gap-2">
                            <div>
                                <label class="block text-xs font-semibold uppercase text-stone-400">Mode</label>
                                <select :name="`rules[${idx}][mode]`" x-model="rule.mode"
                                    class="mt-1 block w-full rounded-lg border border-stone-300 px-2 py-1.5 text-xs">
                                    <option value="include">Include</option>
                                    <option value="exclude">Exclude</option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-xs font-semibold uppercase text-stone-400">Type</label>
                                <select :name="`rules[${idx}][type]`" x-model="rule.type"
                                    class="mt-1 block w-full rounded-lg border border-stone-300 px-2 py-1.5 text-xs">
                                    @foreach ($audienceTypes as $type)
                                        <option value="{{ $type->value }}">{{ $type->label() }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="flex items-end justify-end">
                                <button type="button" @click="removeRule(idx)"
                                    class="rounded-lg bg-red-50 px-3 py-1.5 text-xs font-semibold text-red-700 hover:bg-red-100">
                                    Remove
                                </button>
                            </div>
                        </div>

                        <template x-for="(field, fi) in inputsFor(rule.type)" :key="fi">
                            <div>
                                <label class="block text-xs font-semibold uppercase text-stone-400" x-text="field.label"></label>
                                <template x-if="field.text">
                                    <input type="text" :name="`rules[${idx}][value][${field.name}]`" x-model="rule.value[field.name]"
                                        class="mt-1 block w-full rounded-lg border border-stone-300 px-2 py-1.5 text-xs" />
                                </template>
                                <template x-if="!field.text">
                                    <select :name="`rules[${idx}][value][${field.name}]`" x-model="rule.value[field.name]"
                                        class="mt-1 block w-full rounded-lg border border-stone-300 px-2 py-1.5 text-xs">
                                        <option value="">— pick —</option>
                                        <template x-for="(label, val) in field.options" :key="val">
                                            <option :value="val" x-text="label"></option>
                                        </template>
                                    </select>
                                </template>
                            </div>
                        </template>
                    </div>
                </template>

                <div class="rounded-lg bg-stone-50 border border-stone-200 p-4">
                    <div class="flex items-center justify-between">
                        <p class="text-sm font-semibold text-stone-800">Preview</p>
                        <button type="button" @click="refreshPreview()"
                            class="rounded-lg bg-white ring-1 ring-stone-200 px-3 py-1.5 text-xs font-semibold text-stone-700 hover:bg-stone-100">
                            Refresh preview
                        </button>
                    </div>
                    <p class="mt-3 text-sm text-stone-900">
                        <span x-text="previewCount"></span>
                        <span class="text-stone-500">recipient(s)</span>
                        <span x-show="previewing" class="ml-2 text-xs text-stone-400">Loading…</span>
                    </p>
                </div>
            </div>

            <div class="flex items-center gap-3">
                <button type="submit"
                    class="rounded-xl bg-emerald-700 px-5 py-2.5 text-sm font-semibold text-white hover:bg-emerald-800">
                    {{ $list ? 'Save changes' : 'Create list' }}
                </button>
            </div>
        </form>
    </div>

    @push('scripts')
        <script>
            function savedListForm(config) {
                return {
                    rules: (config.initialRules || []).map(r => ({
                        mode: r.mode || 'include',
                        type: r.type || 'active_members',
                        value: (r.value && typeof r.value === 'object') ? { ...r.value } : {},
                    })),
                    previewCount: 0,
                    previewing: false,
                    audienceInputs: config.audienceInputs,

                    init() {
                        this.refreshPreview();
                    },

                    addRule() {
                        this.rules.push({ mode: 'include', type: 'active_members', value: {} });
                    },

                    removeRule(idx) {
                        this.rules.splice(idx, 1);
                    },

                    inputsFor(type) {
                        return this.audienceInputs[type] || [];
                    },

                    async refreshPreview() {
                        this.previewing = true;
                        try {
                            const res = await fetch(config.previewUrl, {
                                method: 'POST',
                                credentials: 'same-origin',
                                headers: {
                                    'Content-Type': 'application/json',
                                    'X-CSRF-TOKEN': config.csrf,
                                    'Accept': 'application/json',
                                },
                                body: JSON.stringify({ rules: this.rules }),
                            });
                            if (!res.ok) throw new Error('preview failed');
                            const json = await res.json();
                            this.previewCount = json.count;
                        } catch (e) {
                            console.warn('[SAPRF] saved list preview failed', e);
                            this.previewCount = 0;
                        } finally {
                            this.previewing = false;
                        }
                    },
                };
            }
        </script>
    @endpush
</x-layouts.app>
