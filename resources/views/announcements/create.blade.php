<x-layouts.app :title="'Compose Announcement'">
    @php
        // Every audience type + the client-side inputs it needs. The composer
        // reads this map to render the correct value picker per rule. Keys
        // match AudienceType enum values.
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
    @endphp

    <div class="max-w-4xl space-y-6"
        x-data="announcementComposer(@js([
            'previewUrl' => route('announcements.preview'),
            'csrf' => csrf_token(),
            'audienceInputs' => $audienceValueInputs,
        ]))">

        <div class="flex items-center justify-between">
            <div>
                <h1 class="font-heading text-3xl font-bold text-stone-900">Compose Announcement</h1>
                <p class="mt-1 text-sm text-stone-500">Send to segments, individuals or saved lists. Recipients are frozen at send time.</p>
            </div>
            <a href="{{ route('announcements.index') }}" class="text-sm font-medium text-emerald-700 hover:text-emerald-800">← Back</a>
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

        <form method="POST" action="{{ route('announcements.store') }}" enctype="multipart/form-data" class="space-y-6">
            @csrf

            <div class="rounded-xl border border-stone-200 bg-white p-6 shadow-sm space-y-5">
                <div>
                    <label class="block text-sm font-medium text-stone-700">Title</label>
                    <input type="text" name="title" required maxlength="200"
                        value="{{ old('title') }}"
                        class="mt-1 block w-full rounded-lg border border-stone-300 px-3 py-2 text-sm text-stone-900 focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500">
                </div>

                <div>
                    <label class="block text-sm font-medium text-stone-700">Body</label>
                    <textarea name="body" required rows="8" maxlength="10000"
                        class="mt-1 block w-full rounded-lg border border-stone-300 px-3 py-2 text-sm text-stone-900 focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500">{{ old('body') }}</textarea>
                    <p class="mt-1 text-xs text-stone-400">Plain text or basic markdown. Line breaks are preserved.</p>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-stone-700">Category</label>
                        <select name="category" x-model="category" required
                            class="mt-1 block w-full rounded-lg border border-stone-300 px-3 py-2 text-sm text-stone-900 focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500">
                            @foreach ($categories as $cat)
                                <option value="{{ $cat->value }}" @selected(old('category') === $cat->value)>{{ $cat->label() }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-stone-700">Priority</label>
                        <select name="priority"
                            class="mt-1 block w-full rounded-lg border border-stone-300 px-3 py-2 text-sm text-stone-900 focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500">
                            @foreach ($priorities as $prio)
                                <option value="{{ $prio->value }}" @selected(old('priority', 'normal') === $prio->value)>{{ $prio->label() }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-stone-700">Expires (optional)</label>
                        <input type="datetime-local" name="expires_at"
                            value="{{ old('expires_at') }}"
                            class="mt-1 block w-full rounded-lg border border-stone-300 px-3 py-2 text-sm text-stone-900 focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500">
                    </div>
                </div>

                <label class="flex items-start gap-3 rounded-lg border border-stone-200 p-3">
                    <input type="checkbox" name="requires_acknowledgement" value="1"
                        :checked="requiresAck"
                        @change="requiresAck = $event.target.checked"
                        class="mt-0.5 rounded border-stone-300 text-emerald-600 focus:ring-emerald-500">
                    <div>
                        <span class="text-sm font-semibold text-stone-900">Require acknowledgement</span>
                        <p class="text-xs text-stone-500 mt-0.5">Recipients see an "I acknowledge" button; you get a per-recipient receipt. Defaults on for Policy change.</p>
                    </div>
                </label>

                <div>
                    <label class="block text-sm font-medium text-stone-700">Attachments (optional)</label>
                    <input type="file" name="attachments[]" multiple
                        accept=".pdf,.jpg,.jpeg,.png,.webp,.docx,.doc,.xlsx,.xls,.txt"
                        class="mt-1 block w-full text-sm text-stone-700 file:mr-3 file:rounded-lg file:border-0 file:bg-emerald-50 file:px-3 file:py-2 file:text-sm file:font-semibold file:text-emerald-700 hover:file:bg-emerald-100">
                    <p class="mt-1 text-xs text-stone-400">Up to 10 files, 10 MB each. Files stay on a private disk — only recipients and Exco can download them.</p>
                </div>
            </div>

            {{-- Audience rules --}}
            <div class="rounded-xl border border-stone-200 bg-white p-6 shadow-sm space-y-4">
                <div class="flex items-center justify-between">
                    <div>
                        <h2 class="font-heading text-base font-semibold text-stone-900">Audience</h2>
                        <p class="text-xs text-stone-400 mt-1">Add include and exclude rules. The recipient list is frozen when you send.</p>
                    </div>
                    <button type="button" @click="addRule()"
                        class="inline-flex items-center gap-1 rounded-lg bg-emerald-50 px-3 py-1.5 text-xs font-semibold text-emerald-700 hover:bg-emerald-100">
                        + Add rule
                    </button>
                </div>

                <template x-for="(rule, idx) in rules" :key="idx">
                    <div class="rounded-lg border border-stone-200 p-3 space-y-2">
                        <div class="grid grid-cols-1 sm:grid-cols-3 gap-2">
                            <div>
                                <label class="block text-xs font-semibold uppercase text-stone-400">Mode</label>
                                <select :name="`audiences[${idx}][mode]`" x-model="rule.mode"
                                    class="mt-1 block w-full rounded-lg border border-stone-300 px-2 py-1.5 text-xs">
                                    <option value="include">Include</option>
                                    <option value="exclude">Exclude</option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-xs font-semibold uppercase text-stone-400">Type</label>
                                <select :name="`audiences[${idx}][type]`" x-model="rule.type"
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

                        {{-- Per-type value inputs, rendered client-side from audienceInputs. --}}
                        <template x-for="(field, fi) in inputsFor(rule.type)" :key="fi">
                            <div>
                                <label class="block text-xs font-semibold uppercase text-stone-400" x-text="field.label"></label>
                                <template x-if="field.text">
                                    <input type="text" :name="`audiences[${idx}][value][${field.name}]`" x-model="rule.value[field.name]"
                                        class="mt-1 block w-full rounded-lg border border-stone-300 px-2 py-1.5 text-xs" />
                                </template>
                                <template x-if="!field.text">
                                    <select :name="`audiences[${idx}][value][${field.name}]`" x-model="rule.value[field.name]"
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
                        <div>
                            <p class="text-sm font-semibold text-stone-800">Preview</p>
                            <p class="text-xs text-stone-500 mt-0.5">Recipients are re-checked when you press Send.</p>
                        </div>
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
                    <template x-if="previewSample.length">
                        <ul class="mt-2 space-y-1 text-xs text-stone-500">
                            <template x-for="user in previewSample" :key="user.id">
                                <li x-text="`${user.name} — ${user.email}`"></li>
                            </template>
                        </ul>
                    </template>
                </div>
            </div>

            <div class="flex items-center gap-3">
                <button type="submit" name="action" value="draft"
                    class="rounded-xl bg-stone-100 px-5 py-2.5 text-sm font-semibold text-stone-700 hover:bg-stone-200 transition">
                    Save draft
                </button>
                <button type="submit" name="action" value="send"
                    :disabled="previewCount === 0"
                    class="rounded-xl bg-emerald-700 px-5 py-2.5 text-sm font-semibold text-white hover:bg-emerald-800 transition disabled:cursor-not-allowed disabled:opacity-50">
                    Send now
                </button>
                <span class="text-xs text-stone-400" x-show="previewCount === 0">Preview must return &gt; 0 recipients before sending.</span>
            </div>
        </form>
    </div>

    @push('scripts')
        <script>
            function announcementComposer(config) {
                const mandatoryCategories = @json(collect(App\Enums\AnnouncementCategory::cases())->filter(fn ($c) => $c->isMandatory())->pluck('value'));
                const ackDefault = @json(collect(App\Enums\AnnouncementCategory::cases())->filter(fn ($c) => $c->defaultRequiresAcknowledgement())->pluck('value'));

                return {
                    rules: [
                        { mode: 'include', type: 'active_members', value: {} }
                    ],
                    category: '{{ old('category', 'announcement') }}',
                    requiresAck: {{ old('requires_acknowledgement') ? 'true' : 'false' }},
                    previewCount: 0,
                    previewSample: [],
                    previewing: false,
                    audienceInputs: config.audienceInputs,

                    init() {
                        // Default the ack flag on when the operator picks a Policy change.
                        this.$watch('category', (value) => {
                            if (ackDefault.includes(value)) {
                                this.requiresAck = true;
                            }
                        });
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
                                body: JSON.stringify({ audiences: this.rules }),
                            });
                            if (!res.ok) throw new Error('preview failed');
                            const json = await res.json();
                            this.previewCount = json.count;
                            this.previewSample = json.sample;
                        } catch (e) {
                            console.warn('[SAPRF] preview failed', e);
                            this.previewCount = 0;
                            this.previewSample = [];
                        } finally {
                            this.previewing = false;
                        }
                    },
                };
            }
        </script>
    @endpush
</x-layouts.app>
