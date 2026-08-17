{{--
    Membership fee selection for the join / renew cards.
    Expects: $feeTiers (collection of tiers the applicant qualifies for),
             $selectedTier (model|null), $fee (float), $action (route url),
             $buttonLabel (string).

    Tiers are pre-filtered by age in the controller. When the applicant
    qualifies for exactly one tier (typical Junior case), we skip the
    radio group and show a read-only summary card explaining that the
    tier is picked from their date of birth.
--}}
@if($feeTiers->count() === 1)
    @php($only = $feeTiers->first())
    <form method="POST" action="{{ $action }}"
          class="rounded-lg bg-emerald-50 border border-emerald-200 p-5 space-y-4">
        @csrf
        @foreach(($hidden ?? []) as $name => $value)
            <input type="hidden" name="{{ $name }}" value="{{ $value }}">
        @endforeach
        <input type="hidden" name="fee_tier_id" value="{{ $only->id }}">

        <div class="flex items-center justify-between rounded-lg bg-white border border-emerald-200 p-4">
            <div>
                <p class="text-xs font-semibold uppercase tracking-wide text-emerald-700">Selected by age</p>
                <p class="text-sm font-medium text-stone-900 mt-1">{{ $only->name }} Membership</p>
                @if($only->description)
                    <p class="text-xs text-stone-500 mt-1">{{ $only->description }}</p>
                @endif
            </div>
            <p class="text-2xl font-bold text-stone-900 whitespace-nowrap">R {{ number_format((float) $only->price, 2) }}</p>
        </div>

        <p class="text-xs text-stone-400">Tier is set automatically from date of birth. Valid for 12 months from date of payment.</p>

        <button type="submit" class="inline-flex items-center gap-2 rounded-lg bg-emerald-700 px-6 py-3 text-sm font-semibold text-white shadow-sm hover:bg-emerald-800 focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:ring-offset-2">
            {{ $buttonLabel }}
        </button>
    </form>
@elseif($feeTiers->isNotEmpty())
    <form method="POST" action="{{ $action }}"
          x-data="{ tier: '{{ old('fee_tier_id', $selectedTier?->id) }}' }"
          class="rounded-lg bg-emerald-50 border border-emerald-200 p-5 space-y-4">
        @csrf
        @foreach(($hidden ?? []) as $name => $value)
            <input type="hidden" name="{{ $name }}" value="{{ $value }}">
        @endforeach
        <p class="text-sm font-medium text-stone-700">Choose your membership</p>

        <div class="space-y-2">
            @foreach($feeTiers as $t)
                <label class="flex items-center justify-between gap-3 rounded-lg border bg-white px-4 py-3 cursor-pointer transition"
                       :class="tier === '{{ $t->id }}' ? 'border-emerald-500 ring-1 ring-emerald-500' : 'border-stone-200 hover:border-stone-300'">
                    <span class="flex items-center gap-3">
                        <input type="radio" name="fee_tier_id" value="{{ $t->id }}" x-model="tier" required
                               class="text-emerald-600 focus:ring-emerald-500 border-stone-300">
                        <span>
                            <span class="block text-sm font-medium text-stone-900">{{ $t->name }}</span>
                            @if($t->description)
                                <span class="block text-xs text-stone-400">{{ $t->description }}</span>
                            @endif
                        </span>
                    </span>
                    <span class="text-sm font-bold text-stone-900 whitespace-nowrap">R {{ number_format((float) $t->price, 2) }}</span>
                </label>
            @endforeach
        </div>

        <p class="text-xs text-stone-400">Available tiers are filtered by date of birth. Proof of eligibility may be required for discounted rates. Valid for 12 months from date of payment.</p>

        <button type="submit" class="inline-flex items-center gap-2 rounded-lg bg-emerald-700 px-6 py-3 text-sm font-semibold text-white shadow-sm hover:bg-emerald-800 focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:ring-offset-2">
            {{ $buttonLabel }}
        </button>
    </form>
@else
    {{-- No tiers configured — fall back to the single fee. --}}
    <div class="flex items-center justify-between rounded-lg bg-emerald-50 border border-emerald-200 p-5">
        <div>
            <p class="text-sm font-medium text-stone-700">Annual Membership Fee</p>
            <p class="text-3xl font-bold text-stone-900 mt-1">R {{ number_format($fee, 2) }}</p>
            <p class="text-xs text-stone-400 mt-1">Valid for 12 months from date of payment</p>
        </div>
        <form method="POST" action="{{ $action }}">
            @csrf
            @foreach(($hidden ?? []) as $name => $value)
                <input type="hidden" name="{{ $name }}" value="{{ $value }}">
            @endforeach
            <button type="submit" class="inline-flex items-center gap-2 rounded-lg bg-emerald-700 px-6 py-3 text-sm font-semibold text-white shadow-sm hover:bg-emerald-800 focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:ring-offset-2">
                {{ $buttonLabel }}
            </button>
        </form>
    </div>
@endif
