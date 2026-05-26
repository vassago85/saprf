<x-layouts.app :title="'Create Payout - SAPRF'">
    <div class="space-y-6">
        <div>
            <a href="{{ route('financials.payouts') }}" class="text-sm text-emerald-700 hover:text-emerald-800 font-medium">&larr; Back to Payouts</a>
            <h1 class="font-heading text-3xl font-bold text-stone-900 tracking-tight mt-2">Create Payout</h1>
            <p class="mt-1 text-sm text-stone-500">Generate a payout record for a completed match.</p>
        </div>

        @if($matches->isEmpty())
        <div class="rounded-xl border border-stone-200 bg-stone-50 p-6 text-center">
            <p class="text-sm text-stone-500">All completed matches already have payouts generated, or there are no completed matches.</p>
        </div>
        @else
        <form method="POST" action="{{ route('financials.payouts.store') }}" class="rounded-xl border border-stone-200 bg-white shadow-sm p-6 space-y-5 max-w-xl">
            @csrf

            <div>
                <label for="match_id" class="block text-sm font-medium text-stone-700 mb-1">Select Match</label>
                <select name="match_id" id="match_id" required
                        class="w-full rounded-lg border border-stone-300 text-sm py-2.5 px-3 focus:ring-emerald-500 focus:border-emerald-500">
                    <option value="">— Choose a match —</option>
                    @foreach($matches as $match)
                    <option value="{{ $match->id }}">
                        {{ $match->name }} ({{ $match->match_date?->format('d M Y') }}) — MD: {{ $match->user?->name }}
                    </option>
                    @endforeach
                </select>
                @error('match_id') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>

            <div>
                <label for="notes" class="block text-sm font-medium text-stone-700 mb-1">Notes (optional)</label>
                <textarea name="notes" id="notes" rows="3" maxlength="500"
                          class="w-full rounded-lg border border-stone-300 text-sm py-2.5 px-3 focus:ring-emerald-500 focus:border-emerald-500"
                          placeholder="Any notes about this payout..."></textarea>
            </div>

            <div class="pt-2">
                <button type="submit"
                        class="rounded-xl bg-emerald-700 px-6 py-2.5 text-sm font-semibold text-white hover:bg-emerald-800 transition">
                    Generate Payout
                </button>
            </div>
        </form>
        @endif
    </div>
</x-layouts.app>
