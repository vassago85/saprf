<x-layouts.app :title="$case ? 'Edit case' : 'Open case'">
    <div class="max-w-3xl space-y-6"
        x-data="disciplinarySubjectPicker(@js([
            'searchUrl' => route('exco.disciplinary.subject-search'),
            'initialSubjectId' => $case?->subject_user_id,
            'initialSubjectName' => $case?->subject?->name ?? $case?->subject_name,
        ]))">
        <div class="flex items-center justify-between">
            <div>
                <h1 class="font-heading text-3xl font-bold text-stone-900">
                    {{ $case ? 'Edit case ' . $case->reference : 'Open new disciplinary case' }}
                </h1>
                <p class="mt-1 text-sm text-stone-500">
                    {{ $case ? 'Update case metadata. Notes and attachments live on the case page.' : 'Create the case shell. Once opened you can add notes and evidence.' }}
                </p>
            </div>
            <a href="{{ $case ? route('exco.disciplinary.show', $case) : route('exco.disciplinary.index') }}"
                class="text-sm font-medium text-emerald-700 hover:text-emerald-800">← Back</a>
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
            action="{{ $case ? route('exco.disciplinary.update', $case) : route('exco.disciplinary.store') }}"
            class="space-y-5 rounded-xl border border-stone-200 bg-white p-6 shadow-sm">
            @csrf
            @if ($case) @method('PUT') @endif

            <div>
                <label class="block text-sm font-medium text-stone-700">Subject — SAPRF member</label>
                <input type="hidden" name="subject_user_id" x-bind:value="selectedId">
                <input type="text" placeholder="Type a member name or SAPRF #" autocomplete="off"
                    x-model="query"
                    @input.debounce.250ms="search()"
                    @focus="showResults = true"
                    class="mt-1 block w-full rounded-lg border border-stone-300 px-3 py-2 text-sm">
                <div x-show="showResults && results.length > 0" x-cloak
                    class="relative">
                    <ul class="absolute z-10 mt-1 max-h-56 w-full overflow-auto rounded-lg border border-stone-200 bg-white shadow-lg">
                        <template x-for="r in results" :key="r.id">
                            <li>
                                <button type="button"
                                    @click="pick(r)"
                                    class="block w-full px-3 py-2 text-left text-sm hover:bg-emerald-50">
                                    <span class="font-medium text-stone-900" x-text="r.name"></span>
                                    <span class="ml-2 text-xs text-stone-500" x-text="r.saprf_number ? '#' + r.saprf_number : ''"></span>
                                </button>
                            </li>
                        </template>
                    </ul>
                </div>
                <p class="mt-1 text-xs text-stone-400">
                    <span x-show="selectedId">Linked to <strong x-text="selectedName"></strong> · </span>
                    <button type="button" @click="clear()" class="text-emerald-700 hover:text-emerald-800" x-show="selectedId">Clear link</button>
                </p>
            </div>

            <div>
                <label class="block text-sm font-medium text-stone-700">Subject — free-text (used when not a SAPRF member)</label>
                <input type="text" name="subject_name" maxlength="200"
                    value="{{ old('subject_name', $case?->subject_user_id ? null : $case?->subject_name) }}"
                    placeholder="e.g. Non-member spectator"
                    class="mt-1 block w-full rounded-lg border border-stone-300 px-3 py-2 text-sm">
                <p class="mt-1 text-xs text-stone-400">Only fill this in if the subject is not on the platform. Leave blank if you picked a member above.</p>
            </div>

            <div>
                <label for="title" class="block text-sm font-medium text-stone-700">Title / short description</label>
                <input id="title" type="text" name="title" required maxlength="200"
                    value="{{ old('title', $case?->title) }}"
                    placeholder="e.g. Safety violation at Bloem Regional 2026"
                    class="mt-1 block w-full rounded-lg border border-stone-300 px-3 py-2 text-sm">
            </div>

            <div>
                <label for="summary" class="block text-sm font-medium text-stone-700">Summary</label>
                <textarea id="summary" name="summary" rows="4" maxlength="10000"
                    class="mt-1 block w-full rounded-lg border border-stone-300 px-3 py-2 text-sm">{{ old('summary', $case?->summary) }}</textarea>
                <p class="mt-1 text-xs text-stone-400">One or two paragraphs. Detailed evidence goes in notes/attachments on the case page.</p>
            </div>

            <div>
                <label for="status" class="block text-sm font-medium text-stone-700">Status</label>
                <select id="status" name="status"
                    class="mt-1 block w-full rounded-lg border border-stone-300 px-3 py-2 text-sm">
                    @foreach ($statuses as $status)
                        <option value="{{ $status->value }}"
                            @selected(old('status', $case?->status->value ?? 'reported') === $status->value)>
                            {{ $status->label() }}
                        </option>
                    @endforeach
                </select>
            </div>

            <div class="flex items-center gap-3 pt-2">
                <button type="submit"
                    class="rounded-xl bg-emerald-700 px-5 py-2.5 text-sm font-semibold text-white hover:bg-emerald-800">
                    {{ $case ? 'Save changes' : 'Open case' }}
                </button>
            </div>
        </form>
    </div>

    @push('scripts')
        <script>
            function disciplinarySubjectPicker(config) {
                return {
                    query: config.initialSubjectName || '',
                    selectedId: config.initialSubjectId || null,
                    selectedName: config.initialSubjectName || '',
                    results: [],
                    showResults: false,

                    async search() {
                        const q = (this.query || '').trim();
                        if (q.length < 2) {
                            this.results = [];
                            return;
                        }

                        try {
                            const res = await fetch(config.searchUrl + '?q=' + encodeURIComponent(q), {
                                credentials: 'same-origin',
                                headers: { 'Accept': 'application/json' },
                            });
                            if (!res.ok) throw new Error('search failed');
                            const json = await res.json();
                            this.results = json.results || [];
                            this.showResults = true;
                        } catch (e) {
                            console.warn('[SAPRF] disciplinary subject search failed', e);
                            this.results = [];
                        }
                    },

                    pick(r) {
                        this.selectedId = r.id;
                        this.selectedName = r.name;
                        this.query = r.name;
                        this.results = [];
                        this.showResults = false;
                    },

                    clear() {
                        this.selectedId = null;
                        this.selectedName = '';
                        this.query = '';
                        this.results = [];
                    },
                };
            }
        </script>
    @endpush
</x-layouts.app>
