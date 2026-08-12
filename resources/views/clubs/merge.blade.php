<x-layouts.app :title="'Merge ' . $source->name">
    <div class="max-w-3xl space-y-6">
        <div>
            <a href="{{ route('clubs.index') }}" class="text-sm text-stone-500 hover:text-stone-800">← Back to clubs</a>
            <h1 class="mt-2 font-heading text-3xl font-bold text-stone-900 tracking-tight">Merge Club</h1>
            <p class="mt-1 text-sm text-stone-500">Move every member of <strong>{{ $source->name }}</strong> into another club, then delete this one. Useful for cleaning up duplicates from CSV imports.</p>
        </div>

        <div class="rounded-xl border border-amber-200 bg-amber-50 p-5">
            <div class="text-sm text-amber-900">
                <p class="font-semibold">You are about to merge:</p>
                <p class="mt-1 font-mono text-base">{{ $source->name }} ({{ $source->users_count }} {{ Str::plural('member', $source->users_count) }})</p>
                <p class="mt-3 text-xs text-amber-800">The source club will be permanently deleted after the merge. All {{ $source->users_count }} {{ Str::plural('member', $source->users_count) }} will be reassigned to the target club in one transaction. This action is reversible only by re-creating the source and re-assigning members individually.</p>
            </div>
        </div>

        @if ($errors->any())
            <div class="rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-800">
                <ul class="list-disc list-inside">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <form method="POST" action="{{ route('clubs.merge', $source) }}" class="rounded-xl border border-stone-200 bg-white p-6 shadow-sm space-y-5" onsubmit="return confirm('Merge {{ $source->name }} into the selected club and delete {{ $source->name }}?');">
            @csrf

            <div>
                <label for="target_id" class="block text-sm font-medium text-stone-700 mb-1">Target club <span class="text-red-500">*</span></label>
                <select name="target_id" id="target_id" required class="w-full rounded-lg border border-stone-300 text-sm py-2.5 px-3 focus:ring-emerald-500 focus:border-emerald-500">
                    <option value="">— Select a target club —</option>
                    @foreach ($targets as $t)
                        <option value="{{ $t->id }}">
                            {{ $t->name }}
                            @if ($t->province) — {{ $t->province->name }} @endif
                            ({{ $t->users_count }} {{ Str::plural('member', $t->users_count) }})
                            @unless ($t->saprf_recognised) [not SAPRF-recognised] @endunless
                        </option>
                    @endforeach
                </select>
            </div>

            <div class="flex items-center gap-3 border-t border-stone-200 pt-4">
                <button type="submit" class="rounded-lg bg-amber-600 px-5 py-2.5 text-sm font-semibold text-white hover:bg-amber-700">Merge &amp; Delete Source</button>
                <a href="{{ route('clubs.index') }}" class="text-sm text-stone-500 hover:text-stone-800">Cancel</a>
            </div>
        </form>
    </div>
</x-layouts.app>
