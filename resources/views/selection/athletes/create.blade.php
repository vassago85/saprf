<x-layouts.app :title="'Register athlete'">
    <div class="max-w-xl space-y-6">
        <div>
            <div class="text-xs font-semibold uppercase tracking-widest text-stone-400">{{ $cycle->series }} {{ $cycle->season }}</div>
            <h1 class="font-heading text-3xl font-bold text-stone-900 tracking-tight">Register athlete</h1>
        </div>
        <form method="POST" action="{{ route('selection.cycles.athletes.store', $cycle) }}" class="rounded-xl border border-stone-200 bg-white p-6 shadow-sm space-y-4">
            @csrf
            <div>
                <label class="block text-sm font-medium text-stone-700 mb-1">User ID <span class="text-red-500">*</span></label>
                <input type="number" name="user_id" required value="{{ old('user_id') }}" class="block w-full rounded-lg border border-stone-300 text-sm">
                <p class="mt-1 text-xs text-stone-500">Use the user's numeric id (bulk-register is easier for the common case).</p>
            </div>
            <div>
                <label class="block text-sm font-medium text-stone-700 mb-1">Claimed division</label>
                <select name="claimed_division_id" class="block w-full rounded-lg border border-stone-300 text-sm">
                    <option value="">(unset)</option>
                    @foreach ($divisions as $d)
                        <option value="{{ $d->id }}" @selected(old('claimed_division_id') == $d->id)>{{ $d->name }}</option>
                    @endforeach
                </select>
            </div>
            @if ($errors->any())
                <ul class="list-disc list-inside text-sm text-red-700">
                    @foreach ($errors->all() as $e) <li>{{ $e }}</li> @endforeach
                </ul>
            @endif
            <div class="flex items-center justify-end gap-2">
                <a href="{{ route('selection.cycles.athletes.index', $cycle) }}" class="rounded-lg border border-stone-300 bg-white px-4 py-2 text-sm font-medium text-stone-700 hover:bg-stone-50">Cancel</a>
                <button type="submit" class="rounded-lg bg-emerald-700 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-800">Register</button>
            </div>
        </form>
    </div>
</x-layouts.app>
