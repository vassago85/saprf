<x-layouts.app :title="'ExCo — AI Prompts'">
    <div class="max-w-4xl space-y-6">
        <div>
            <a href="{{ route('exco.meetings.index') }}" class="text-sm font-medium text-emerald-700 hover:text-emerald-800">← ExCo</a>
            <h1 class="mt-1 font-heading text-3xl font-bold text-stone-900">AI prompts</h1>
            <p class="mt-1 text-sm text-stone-500">
                Reusable prompts for turning a meeting notice into JSON, and a transcript into structured minutes.
                Copy the prompt, paste it above your source text in Claude / ChatGPT / your model of choice, then
                paste the output back into the platform.
            </p>
        </div>

        <div class="rounded-xl border border-amber-200 bg-amber-50 p-4 text-xs text-amber-900">
            <p class="font-semibold">Handling sensitive content</p>
            <p class="mt-1">
                Do not paste disciplinary case detail (member names, medical, legal-privileged material) into an
                external AI unless the model is confirmed to run on data you control. Use initials or the case
                reference (e.g. <code>DC-2026-001</code>) instead of names in the raw notes.
            </p>
        </div>

        {{-- Prompt 1: Notice → JSON --}}
        <div class="rounded-xl border border-stone-200 bg-white shadow-sm" x-data="{ copied: false }">
            <div class="flex items-center justify-between border-b border-stone-200 px-5 py-4">
                <div>
                    <h2 class="font-heading text-lg font-semibold text-stone-900">Meeting notice → JSON</h2>
                    <p class="mt-0.5 text-xs text-stone-500">
                        Paste the resulting JSON into
                        <a href="{{ route('exco.meetings.import.form') }}" class="font-semibold text-emerald-700 hover:text-emerald-800">Meetings → Import from JSON</a>
                        to create a new meeting + its full agenda in one shot.
                    </p>
                </div>
                <button type="button"
                    @click="navigator.clipboard.writeText($refs.notice.innerText); copied = true; setTimeout(() => copied = false, 2000)"
                    class="rounded-lg bg-emerald-700 px-3 py-1.5 text-xs font-semibold text-white hover:bg-emerald-800">
                    <span x-show="!copied">Copy prompt</span>
                    <span x-show="copied" x-cloak>Copied ✓</span>
                </button>
            </div>
            <pre x-ref="notice" class="max-h-[420px] overflow-auto bg-stone-950 px-5 py-4 text-xs leading-relaxed text-stone-100 whitespace-pre-wrap">{{ $noticeToJson }}</pre>
        </div>

        {{-- Prompt 2: Transcript → minutes JSON (bulk import) --}}
        <div class="rounded-xl border border-stone-200 bg-white shadow-sm" x-data="{ copied: false }">
            <div class="flex items-center justify-between border-b border-stone-200 px-5 py-4">
                <div>
                    <h2 class="font-heading text-lg font-semibold text-stone-900">Transcript → minutes JSON (bulk import)</h2>
                    <p class="mt-0.5 text-xs text-stone-500">
                        Use after the meeting. Paste the resulting JSON into the <em>Bulk import minutes</em> block
                        on the meeting show page — it populates every agenda item and creates the action items in
                        one submit. Prefer the meeting-specific version of this prompt on the show page itself,
                        which bakes the exact agenda in.
                    </p>
                </div>
                <button type="button"
                    @click="navigator.clipboard.writeText($refs.mjson.innerText); copied = true; setTimeout(() => copied = false, 2000)"
                    class="rounded-lg bg-emerald-700 px-3 py-1.5 text-xs font-semibold text-white hover:bg-emerald-800">
                    <span x-show="!copied">Copy prompt</span>
                    <span x-show="copied" x-cloak>Copied ✓</span>
                </button>
            </div>
            <pre x-ref="mjson" class="max-h-[420px] overflow-auto bg-stone-950 px-5 py-4 text-xs leading-relaxed text-stone-100 whitespace-pre-wrap">{{ $transcriptToMinutesJson }}</pre>
        </div>

        {{-- Prompt 3: Transcript → prose minutes (legacy / manual paste) --}}
        <div class="rounded-xl border border-stone-200 bg-white shadow-sm" x-data="{ copied: false }">
            <div class="flex items-center justify-between border-b border-stone-200 px-5 py-4">
                <div>
                    <h2 class="font-heading text-lg font-semibold text-stone-900">Transcript → prose minutes (manual paste)</h2>
                    <p class="mt-0.5 text-xs text-stone-500">
                        Alternative for anyone who prefers to paste minutes per item by hand. Outputs one prose
                        block per agenda item; paste each block into that item's Minutes field. Prefer the JSON
                        version above unless you want to eyeball each block first.
                    </p>
                </div>
                <button type="button"
                    @click="navigator.clipboard.writeText($refs.minutes.innerText); copied = true; setTimeout(() => copied = false, 2000)"
                    class="rounded-lg bg-emerald-700 px-3 py-1.5 text-xs font-semibold text-white hover:bg-emerald-800">
                    <span x-show="!copied">Copy prompt</span>
                    <span x-show="copied" x-cloak>Copied ✓</span>
                </button>
            </div>
            <pre x-ref="minutes" class="max-h-[420px] overflow-auto bg-stone-950 px-5 py-4 text-xs leading-relaxed text-stone-100 whitespace-pre-wrap">{{ $transcriptToMinutes }}</pre>
        </div>

        <div class="rounded-xl border border-stone-200 bg-white p-5 shadow-sm">
            <h3 class="font-heading text-base font-semibold text-stone-900">Workflow</h3>
            <ol class="mt-3 list-decimal space-y-2 pl-5 text-sm text-stone-700">
                <li>
                    <span class="font-semibold">Before the meeting:</span> paste the notice into an AI with the
                    first prompt, then paste the JSON into
                    <a href="{{ route('exco.meetings.import.form') }}" class="font-semibold text-emerald-700 hover:text-emerald-800">Import from JSON</a>.
                </li>
                <li>
                    <span class="font-semibold">During the meeting:</span> record with Happy Scribe (or take rough
                    notes). Don't try to type minutes into the platform live.
                </li>
                <li>
                    <span class="font-semibold">After the meeting:</span> on the meeting show page, open
                    <em>Bulk import minutes from JSON</em>. Copy the meeting-specific prompt (agenda is baked in),
                    feed it plus your transcript to an AI, then paste the JSON back into the textarea and submit —
                    every agenda item gets its minutes and every action item is created in one go.
                </li>
                <li>
                    <span class="font-semibold">Circulate:</span> once minutes are drafted, hit "Download minutes"
                    on the meeting show page, email the PDF to ExCo, and mark it as circulated in the platform.
                </li>
                <li>
                    <span class="font-semibold">Adopt:</span> at the next sitting, mark the previous meeting's
                    minutes as adopted so the paper trail is complete.
                </li>
            </ol>
        </div>
    </div>
</x-layouts.app>
