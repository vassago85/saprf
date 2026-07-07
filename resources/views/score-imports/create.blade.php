<x-layouts.app :title="'Upload Scores'">
    <div class="max-w-3xl space-y-6">
        <div>
            <a href="{{ route('score-imports.index') }}" class="text-sm text-emerald-700 hover:text-emerald-800 font-medium">&larr; All Imports</a>
            <h1 class="mt-2 font-heading text-3xl font-bold text-stone-900 tracking-tight">Upload Scores</h1>
            <p class="mt-1 text-sm text-stone-500">Upload a CSV of match results. The import runs in the background and standings will recalculate automatically.</p>
        </div>

        {{-- CSV Format Help --}}
        <details class="rounded-xl border border-sky-200 bg-sky-50/50 p-4 open:pb-4">
            <summary class="cursor-pointer font-semibold text-sky-900 select-none">CSV format &amp; column requirements</summary>
            <div class="mt-3 text-sm text-sky-900 space-y-2">
                <p>The CSV must have a header row. <strong>Required columns:</strong> <code class="rounded bg-white px-1.5 py-0.5 text-xs">shooter_name</code> and <code class="rounded bg-white px-1.5 py-0.5 text-xs">raw_score</code>.</p>
                <p><strong>Optional columns</strong> that the system understands:</p>
                <ul class="list-disc pl-5 space-y-0.5 text-xs">
                    <li><code class="rounded bg-white px-1.5 py-0.5">email</code> — links the score to an existing user account (recommended)</li>
                    <li><code class="rounded bg-white px-1.5 py-0.5">placement</code> — overall finishing position</li>
                    <li><code class="rounded bg-white px-1.5 py-0.5">division</code> — division slug or name (e.g. <code>open</code>, <code>factory</code>, <code>limited</code>, <code>ladies</code>, <code>junior</code>, <code>senior</code>)</li>
                    <li><code class="rounded bg-white px-1.5 py-0.5">stage_1, stage_2, …</code> — per-stage points for provincial calculation</li>
                </ul>
                <p class="pt-2 text-xs text-sky-700">Column names are flexible — <code>Name</code>, <code>Shooter</code>, <code>Total Score</code>, <code>Points</code>, <code>Rank</code>, <code>Position</code>, <code>Class</code> etc. all map correctly.</p>
                <a href="{{ route('score-imports.template') }}" class="inline-flex items-center gap-1 mt-2 text-xs font-semibold text-sky-900 hover:underline">
                    <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5m-13.5-9L12 3m0 0 4.5 4.5M12 3v13.5"/></svg>
                    Download blank CSV template
                </a>
            </div>
        </details>

        <form method="POST" action="{{ route('score-imports.store') }}" enctype="multipart/form-data" class="space-y-6"
              x-data="{
                matchMeta: @js($matchMeta),
                matchId: '{{ old('match_id', $preselectedMatchId) }}',
                day: '{{ old('day') }}',
                get isTwoDay() { return this.matchId && this.matchMeta[this.matchId]?.is_two_day; }
              }">
            @csrf

            <div class="rounded-2xl border border-stone-200 bg-white shadow-sm p-6 space-y-5">
                <div>
                    <label for="match_id" class="block text-sm font-medium text-stone-700 mb-1">Match <span class="text-red-500">*</span></label>
                    <select name="match_id" id="match_id" required x-model="matchId" class="block w-full rounded-lg border border-stone-300 text-sm py-2.5 focus:ring-emerald-500 focus:border-emerald-500">
                        <option value="">Select match…</option>
                        @foreach ($matches as $match)
                            <option value="{{ $match->id }}">
                                {{ $match->name }} ({{ $match->match_date->format('d M Y') }}{{ $match->isMultiDay() ? ' – 2-day' : '' }})
                            </option>
                        @endforeach
                    </select>
                    @error('match_id') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label for="source_type" class="block text-sm font-medium text-stone-700 mb-1">Source <span class="text-red-500">*</span></label>
                    <select name="source_type" id="source_type" required class="block w-full rounded-lg border border-stone-300 text-sm py-2.5 focus:ring-emerald-500 focus:border-emerald-500">
                        <option value="csv" @selected(old('source_type', 'csv') === 'csv')>CSV file</option>
                        <option value="practiscore" @selected(old('source_type') === 'practiscore')>PractiScore export</option>
                        <option value="impact" @selected(old('source_type') === 'impact')>Impact scoring</option>
                        <option value="manual" @selected(old('source_type') === 'manual')>Manual entry</option>
                        <option value="other" @selected(old('source_type') === 'other')>Other</option>
                    </select>
                    @error('source_type') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>

                {{-- Day picker (only visible for 2-day matches) --}}
                <div x-show="isTwoDay" x-cloak class="rounded-xl border border-blue-200 bg-blue-50/50 p-4">
                    <label class="block text-sm font-medium text-stone-900 mb-2">Which day is this CSV? <span class="text-red-500">*</span></label>
                    <div class="grid grid-cols-2 gap-3">
                        <label class="flex items-start gap-3 rounded-xl border p-3 cursor-pointer transition"
                               :class="day === '1' ? 'border-blue-500 bg-white ring-1 ring-blue-500' : 'border-stone-200 bg-white hover:border-stone-300'">
                            <input type="radio" name="day" value="1" x-model="day" class="mt-0.5 text-blue-600 focus:ring-blue-500">
                            <span class="text-sm">
                                <span class="block font-semibold text-stone-900">Day 1</span>
                                <span class="block text-xs text-stone-500 mt-0.5">Saturday scores. Feeds the provincial-pool contribution.</span>
                            </span>
                        </label>
                        <label class="flex items-start gap-3 rounded-xl border p-3 cursor-pointer transition"
                               :class="day === '2' ? 'border-blue-500 bg-white ring-1 ring-blue-500' : 'border-stone-200 bg-white hover:border-stone-300'">
                            <input type="radio" name="day" value="2" x-model="day" class="mt-0.5 text-blue-600 focus:ring-blue-500">
                            <span class="text-sm">
                                <span class="block font-semibold text-stone-900">Day 2</span>
                                <span class="block text-xs text-stone-500 mt-0.5">Sunday scores. Combined with Day 1 for the national total.</span>
                            </span>
                        </label>
                    </div>
                    <p class="mt-2 text-xs text-blue-800">
                        Upload each day's CSV separately — the system merges them by shooter, so you can upload Day 1 first, then Day 2 later.
                    </p>
                </div>

                <div>
                    <label for="file" class="block text-sm font-medium text-stone-700 mb-1">CSV File <span class="text-red-500">*</span></label>
                    <input
                        type="file"
                        name="file"
                        id="file"
                        accept=".csv,text/csv"
                        required
                        class="block w-full text-sm text-stone-600
                            file:mr-4 file:rounded-lg file:border-0 file:bg-emerald-50 file:px-4 file:py-2.5
                            file:text-sm file:font-medium file:text-emerald-700
                            hover:file:bg-emerald-100 cursor-pointer"
                    />
                    <p class="mt-1 text-xs text-stone-400">CSV only. If you're in Excel, use <em>File → Save As → CSV UTF-8</em>. Max 20 MB.</p>
                    @error('file') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>

                {{-- Replace existing scores --}}
                <div class="rounded-lg border border-amber-200 bg-amber-50/50 p-4">
                    <label class="flex items-start gap-3 cursor-pointer">
                        <input type="hidden" name="replace_existing" value="0">
                        <input type="checkbox" name="replace_existing" id="replace_existing" value="1"
                               @checked(old('replace_existing'))
                               class="mt-1 rounded border-amber-400 text-amber-600 focus:ring-amber-500">
                        <div class="text-sm">
                            <span class="font-semibold text-stone-900">Replace existing scores for this match</span>
                            <p class="mt-0.5 text-xs text-stone-600">Delete any scores already recorded for this match before importing. Use this when re-uploading the definitive results file — otherwise scores will be duplicated.</p>
                        </div>
                    </label>
                </div>
            </div>

            @if ($errors->any())
                <div class="rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-700">
                    <p class="font-semibold mb-1">Please fix these issues:</p>
                    <ul class="list-inside list-disc space-y-1">
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <div class="flex items-center gap-3">
                <button type="submit" class="inline-flex items-center gap-2 rounded-xl bg-emerald-700 px-6 py-2.5 text-sm font-semibold text-white hover:bg-emerald-800 transition">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 16.5V9.75m0 0 3 3m-3-3-3 3M6.75 19.5a4.5 4.5 0 0 1-1.41-8.775 5.25 5.25 0 0 1 10.233-2.33 3 3 0 0 1 3.758 3.848A3.752 3.752 0 0 1 18 19.5H6.75Z"/></svg>
                    Upload &amp; Import
                </button>
                <a href="{{ route('score-imports.index') }}" class="text-sm text-stone-500 hover:text-stone-700">Cancel</a>
            </div>
        </form>
    </div>
</x-layouts.app>
