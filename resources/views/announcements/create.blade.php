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

    @php
        $retentionDefaults = collect(\App\Enums\AnnouncementCategory::cases())
            ->mapWithKeys(fn ($c) => [$c->value => $c->defaultRetention()->value])
            ->all();
        $retentionFixed = collect(\App\Enums\AnnouncementCategory::cases())
            ->filter(fn ($c) => $c->retentionIsFixed())
            ->map(fn ($c) => $c->value)
            ->values()
            ->all();
    @endphp
    <div class="max-w-4xl space-y-6"
        x-data="announcementComposer(@js([
            'previewUrl' => route('announcements.preview'),
            'bodyPreviewUrl' => route('announcements.body-preview'),
            'csrf' => csrf_token(),
            'audienceInputs' => $audienceValueInputs,
            'retentionDefaults' => $retentionDefaults,
            'retentionFixedCategories' => $retentionFixed,
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
                    <div class="flex items-center justify-between mb-1">
                        <label class="block text-sm font-medium text-stone-700">Body</label>
                        <div class="inline-flex rounded-lg border border-stone-200 bg-stone-50 p-0.5 text-xs">
                            <button type="button" @click="bodyView = 'write'"
                                :class="bodyView === 'write' ? 'bg-white text-stone-900 shadow-sm' : 'text-stone-500 hover:text-stone-700'"
                                class="rounded-md px-3 py-1 font-semibold transition">Write</button>
                            <button type="button" @click="bodyView = 'preview'; refreshBodyPreview()"
                                :class="bodyView === 'preview' ? 'bg-white text-stone-900 shadow-sm' : 'text-stone-500 hover:text-stone-700'"
                                class="rounded-md px-3 py-1 font-semibold transition">Preview</button>
                        </div>
                    </div>

                    <textarea name="body" required rows="8" maxlength="10000"
                        x-show="bodyView === 'write'"
                        x-model="body"
                        @input.debounce.400ms="refreshBodyPreview()"
                        class="mt-0 block w-full rounded-lg border border-stone-300 px-3 py-2 text-sm text-stone-900 focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500 font-mono">{{ old('body') }}</textarea>

                    <div x-show="bodyView === 'preview'" x-cloak
                        class="mt-0 min-h-[12rem] rounded-lg border border-stone-300 bg-white px-4 py-3 text-sm text-stone-900">
                        <template x-if="bodyPreviewing">
                            <p class="text-xs text-stone-400 italic">Rendering…</p>
                        </template>
                        <template x-if="!bodyPreviewing && !body.trim()">
                            <p class="text-xs text-stone-400 italic">Nothing to preview yet — switch back to Write and enter some text.</p>
                        </template>
                        <div x-show="!bodyPreviewing && body.trim()"
                            class="prose prose-sm max-w-none prose-headings:font-heading prose-headings:text-stone-900 prose-a:text-emerald-700 hover:prose-a:text-emerald-800"
                            x-html="bodyPreviewHtml"></div>
                    </div>

                    <div class="mt-1 text-xs text-stone-500">
                        <p>Plain text works as-is — line breaks are preserved. Optional formatting:</p>
                        <ul class="mt-1 ml-4 list-disc space-y-0.5 text-stone-400">
                            <li><code class="rounded bg-stone-100 px-1 text-stone-700">**bold**</code> &nbsp; <code class="rounded bg-stone-100 px-1 text-stone-700">*italic*</code></li>
                            <li><code class="rounded bg-stone-100 px-1 text-stone-700">[link text](https://saprf.co.za)</code> — or paste a bare URL, it becomes clickable</li>
                            <li>Lines starting with <code class="rounded bg-stone-100 px-1 text-stone-700">-</code> or <code class="rounded bg-stone-100 px-1 text-stone-700">1.</code> become bulleted / numbered lists</li>
                            <li><code class="rounded bg-stone-100 px-1 text-stone-700">## Heading</code> for a section heading</li>
                            <li>Blank line = new paragraph. HTML tags are shown as literal text — they never render.</li>
                        </ul>
                    </div>
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
                            :disabled="retention !== 'expires_on_date'"
                            class="mt-1 block w-full rounded-lg border border-stone-300 px-3 py-2 text-sm text-stone-900 focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500 disabled:bg-stone-100 disabled:text-stone-400">
                        <p class="mt-1 text-xs text-stone-400" x-show="retention !== 'expires_on_date'">
                            Only used when retention is "Expires on date".
                        </p>
                    </div>
                </div>

                <div>
                    <label class="block text-sm font-medium text-stone-700">Retention</label>
                    <select name="retention" x-model="retention"
                        :disabled="retentionFixed"
                        class="mt-1 block w-full rounded-lg border border-stone-300 px-3 py-2 text-sm text-stone-900 focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500 disabled:bg-stone-100">
                        @foreach ($retentionOptions as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </select>
                    <p class="mt-1 text-xs text-stone-500">
                        <span x-show="retention === 'permanent'">Stays in members' Archive tab forever. Shown in Inbox for 60 days after send, then moves to Archive.</span>
                        <span x-show="retention === 'expires_on_date'">Shown in Inbox until the expiry above passes; stays searchable in Archive after that.</span>
                        <span x-show="retention === 'match_scoped'">Auto-hides from all member views the moment the linked match is marked completed or cancelled.</span>
                        <span x-show="retentionFixed" class="ml-1 italic text-stone-400">Locked for this category.</span>
                    </p>
                </div>

                <div>
                    <p class="text-sm font-medium text-stone-700">Delivery</p>
                    <p class="mt-0.5 text-xs text-stone-500">Uncheck email and push to only publish in Communications — members see it when they next log in. No Mailgun send.</p>
                    <input type="hidden" name="deliver_via[]" value="database">
                    <div class="mt-2 space-y-2">
                        <label class="flex items-start gap-3 rounded-lg border border-stone-200 p-3">
                            <input type="checkbox" name="deliver_via[]" value="mail"
                                @checked(in_array('mail', old('deliver_via', ['mail', 'webpush', 'database']), true))
                                class="mt-0.5 rounded border-stone-300 text-emerald-600 focus:ring-emerald-500">
                            <div>
                                <span class="text-sm font-semibold text-stone-900">Email</span>
                                <p class="text-xs text-stone-500 mt-0.5">Send via Mailgun (capped at 50/hour). Recipients can mute non-mandatory categories.</p>
                            </div>
                        </label>
                        <label class="flex items-start gap-3 rounded-lg border border-stone-200 p-3">
                            <input type="checkbox" name="deliver_via[]" value="webpush"
                                @checked(in_array('webpush', old('deliver_via', ['mail', 'webpush', 'database']), true))
                                class="mt-0.5 rounded border-stone-300 text-emerald-600 focus:ring-emerald-500">
                            <div>
                                <span class="text-sm font-semibold text-stone-900">Push notification</span>
                                <p class="text-xs text-stone-500 mt-0.5">Phone/desktop alert for members who installed the PWA and allowed notifications.</p>
                            </div>
                        </label>
                        <label class="flex items-start gap-3 rounded-lg border border-stone-200 bg-stone-50 p-3">
                            <input type="checkbox" name="deliver_via[]" value="database" checked disabled
                                class="mt-0.5 rounded border-stone-300 text-emerald-600 focus:ring-emerald-500">
                            <div>
                                <span class="text-sm font-semibold text-stone-900">In-app (Communications)</span>
                                <p class="text-xs text-stone-500 mt-0.5">Always on. They see it in their inbox and the nav badge the next time they log in — no email required.</p>
                            </div>
                        </label>
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
                    retention: '{{ old('retention', '') }}',
                    requiresAck: {{ old('requires_acknowledgement') ? 'true' : 'false' }},
                    previewCount: 0,
                    previewSample: [],
                    previewing: false,
                    bodyView: 'write',
                    body: {!! json_encode(old('body', '')) !!},
                    bodyPreviewHtml: '',
                    bodyPreviewing: false,
                    audienceInputs: config.audienceInputs,
                    retentionDefaults: config.retentionDefaults || {},
                    retentionFixedCategories: config.retentionFixedCategories || [],

                    get retentionFixed() {
                        return this.retentionFixedCategories.includes(this.category);
                    },

                    init() {
                        // Default the ack flag on when the operator picks a Policy change.
                        this.$watch('category', (value) => {
                            if (ackDefault.includes(value)) {
                                this.requiresAck = true;
                            }
                            // Snap retention to the category's default. For
                            // pinned categories the server enforces the
                            // value anyway (see resolveRetention), but this
                            // keeps the visible dropdown honest.
                            const nextRetention = this.retentionDefaults[value];
                            if (nextRetention) {
                                this.retention = nextRetention;
                            }
                        });

                        // Prime retention from the current category on
                        // first render so a hard-refresh with `old('category')`
                        // set still shows the correct default.
                        if (!this.retention) {
                            this.retention = this.retentionDefaults[this.category] || 'expires_on_date';
                        }

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

                    async refreshBodyPreview() {
                        if (!config.bodyPreviewUrl) return;
                        this.bodyPreviewing = true;
                        try {
                            const res = await fetch(config.bodyPreviewUrl, {
                                method: 'POST',
                                credentials: 'same-origin',
                                headers: {
                                    'Content-Type': 'application/json',
                                    'X-CSRF-TOKEN': config.csrf,
                                    'Accept': 'application/json',
                                },
                                body: JSON.stringify({ body: this.body }),
                            });
                            if (!res.ok) throw new Error('body preview failed');
                            const json = await res.json();
                            this.bodyPreviewHtml = json.html || '';
                        } catch (e) {
                            console.warn('[SAPRF] body preview failed', e);
                            this.bodyPreviewHtml = '<p class="text-red-600">Preview failed — the announcement will still send, this is just a rendering hiccup.</p>';
                        } finally {
                            this.bodyPreviewing = false;
                        }
                    },
                };
            }
        </script>
    @endpush
</x-layouts.app>
