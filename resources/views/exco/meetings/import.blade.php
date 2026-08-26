<x-layouts.app :title="'Import meeting from JSON'">
    <div class="max-w-4xl space-y-6">
        <div>
            <a href="{{ route('exco.meetings.index') }}" class="text-sm font-medium text-emerald-700 hover:text-emerald-800">← All meetings</a>
            <h1 class="mt-1 font-heading text-3xl font-bold text-stone-900">Import meeting from JSON</h1>
            <p class="mt-1 text-sm text-stone-500">
                Paste a JSON payload matching the schema below to create a new meeting with its full agenda in one
                submit. Use the
                <a href="{{ route('exco.prompts') }}" class="font-semibold text-emerald-700 hover:text-emerald-800">notice→JSON prompt</a>
                to turn a plain meeting notice into this shape.
            </p>
        </div>

        @if ($errors->any())
            <div class="rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-800">
                <p class="font-semibold">Could not import:</p>
                <ul class="mt-2 list-disc space-y-0.5 pl-5">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <form method="POST" action="{{ route('exco.meetings.import') }}"
            class="rounded-xl border border-stone-200 bg-white p-6 shadow-sm space-y-4">
            @csrf
            <div>
                <label class="block text-xs font-semibold uppercase text-stone-500">JSON payload</label>
                <textarea name="payload" rows="18" required
                    class="mt-1 block w-full rounded-lg border border-stone-300 px-3 py-2 font-mono text-xs"
                    placeholder='{ "meeting": { "title": "...", "type": "regular", "scheduled_at": "2026-09-24 18:00" }, "agenda_items": [ ... ] }'>{{ old('payload') }}</textarea>
            </div>
            <div class="flex items-center justify-end gap-2">
                <a href="{{ route('exco.meetings.index') }}"
                    class="rounded-lg bg-white ring-1 ring-stone-200 px-3 py-1.5 text-xs font-semibold text-stone-700 hover:bg-stone-50">
                    Cancel
                </a>
                <button type="submit"
                    class="rounded-lg bg-emerald-700 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-800">
                    Import meeting
                </button>
            </div>
        </form>

        <details class="rounded-xl border border-stone-200 bg-white p-5 shadow-sm">
            <summary class="cursor-pointer text-sm font-semibold text-stone-800">Schema reference</summary>
            <pre class="mt-3 overflow-auto rounded-lg bg-stone-950 px-4 py-3 text-[11px] leading-relaxed text-stone-100 whitespace-pre">{
  "meeting": {
    "title":            "string, required, max 200",
    "type":             "regular | special (default regular)",
    "scheduled_at":     "YYYY-MM-DD HH:MM (24h, SAST)",
    "location":         "string, optional",
    "attendance_notes": "string, optional, multi-line ok"
  },
  "agenda_items": [
    {
      "title":      "string, required, max 200",
      "briefing":   "string, optional, multi-line ok",
      "visibility": "ordinary | confidential (default ordinary)"
    }
  ]
}</pre>
            <p class="mt-3 text-xs text-stone-500">
                Items are inserted in array order. Newlines inside <code>briefing</code> / <code>attendance_notes</code>
                should be escaped as <code>\n</code>.
            </p>
        </details>

        <details class="rounded-xl border border-stone-200 bg-white p-5 shadow-sm" x-data="{ copied: false }">
            <summary class="cursor-pointer text-sm font-semibold text-stone-800">Show the AI prompt (notice → JSON)</summary>
            <div class="mt-3 flex justify-end">
                <button type="button"
                    @click="navigator.clipboard.writeText($refs.p.innerText); copied = true; setTimeout(() => copied = false, 2000)"
                    class="rounded-lg bg-emerald-700 px-3 py-1.5 text-xs font-semibold text-white hover:bg-emerald-800">
                    <span x-show="!copied">Copy prompt</span>
                    <span x-show="copied" x-cloak>Copied ✓</span>
                </button>
            </div>
            <pre x-ref="p" class="mt-2 max-h-[360px] overflow-auto rounded-lg bg-stone-950 px-4 py-3 text-[11px] leading-relaxed text-stone-100 whitespace-pre-wrap">{{ $prompt }}</pre>
        </details>
    </div>
</x-layouts.app>
