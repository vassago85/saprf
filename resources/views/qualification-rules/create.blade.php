<x-layouts.app :title="'Add Qualification Rule'">
    <h1 class="font-heading text-3xl font-bold text-stone-900">Add Qualification Rule</h1>

    <form method="POST" action="{{ route('qualification-rules.store') }}" class="mt-8 max-w-lg space-y-6">
        @csrf

        @if ($errors->any())
            <div class="rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-700">
                <ul class="list-inside list-disc space-y-1">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <div class="rounded-xl border border-stone-200 bg-white p-6 shadow-sm space-y-5">
            <div>
                <label for="series" class="block text-sm font-medium text-stone-700">Series</label>
                <select name="series" id="series" required class="mt-1 block w-full rounded-lg border border-stone-300 px-3 py-2 text-sm text-stone-900 shadow-sm focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500">
                    <option value="">Select series…</option>
                    <option value="PRS" @selected(old('series') === 'PRS')>PRS</option>
                    <option value="PR22" @selected(old('series') === 'PR22')>PR22</option>
                </select>
            </div>

            <div>
                <label for="season" class="block text-sm font-medium text-stone-700">Season</label>
                <input type="number" name="season" id="season" value="{{ old('season', now()->year) }}" min="2020" max="2099" required class="mt-1 block w-full rounded-lg border border-stone-300 px-3 py-2 text-sm text-stone-900 shadow-sm focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500">
            </div>

            <div>
                <label for="min_out_of_province_matches" class="block text-sm font-medium text-stone-700">Min Out-of-Province Matches</label>
                <input type="number" name="min_out_of_province_matches" id="min_out_of_province_matches" value="{{ old('min_out_of_province_matches') }}" min="0" required class="mt-1 block w-full rounded-lg border border-stone-300 px-3 py-2 text-sm text-stone-900 shadow-sm focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500">
            </div>
        </div>

        <div class="flex items-center gap-3">
            <button type="submit" class="inline-flex items-center gap-2 rounded-lg bg-emerald-700 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-emerald-800 focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:ring-offset-2">Create Rule</button>
            <a href="{{ route('qualification-rules.index') }}" class="inline-flex items-center rounded-lg px-4 py-2 text-sm font-medium text-stone-600 hover:bg-stone-100 hover:text-stone-900">Cancel</a>
        </div>
    </form>
</x-layouts.app>
