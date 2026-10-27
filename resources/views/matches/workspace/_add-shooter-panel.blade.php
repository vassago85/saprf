@php
    $availableDivisionsForAdd = $match->availableDivisions();
@endphp
<div class="rounded-xl border border-emerald-200 bg-emerald-50/40 shadow-sm p-6"
     id="add-shooter"
     x-data="adminAddShooter({
        searchUrl: '{{ route('events.members.search', $match) }}',
     })"
     x-init="init()">
    <div class="flex items-start justify-between gap-3">
        <div>
            <h2 class="text-lg font-semibold text-stone-900">Add a Shooter</h2>
            <p class="mt-1 text-sm text-stone-600">
                Book a shooter into this match manually — for entries paid off-platform
                (cash, EFT, comp'd). Confirmed and marked paid on save; no PayFast charge.
            </p>
        </div>
        <span class="inline-flex items-center rounded-full bg-emerald-100 px-2 py-0.5 text-[10px] font-semibold text-emerald-800 ring-1 ring-inset ring-emerald-300">
            MD / Admin
        </span>
    </div>

    @if($selfEntry)
        <div class="mt-4 flex flex-wrap items-center justify-between gap-3 rounded-lg border border-emerald-200 bg-white px-3 py-2.5">
            <p class="text-sm text-stone-700">You're entered in this match{{ $selfEntry->division ? ' ('.$selfEntry->division->name.')' : '' }}.</p>
            <a href="{{ route('registrations.show', $selfEntry) }}" class="text-xs font-semibold text-emerald-700 hover:text-emerald-800">View my entry →</a>
        </div>
    @elseif($availableDivisionsForAdd->isNotEmpty())
        <form method="POST" action="{{ route('matches.entries.store', $match) }}"
              class="mt-4 flex flex-wrap items-center gap-3 rounded-lg border border-emerald-200 bg-white px-3 py-2.5">
            @csrf
            <input type="hidden" name="user_id" value="{{ auth()->id() }}">
            <p class="text-sm font-medium text-stone-800">Shooting this match yourself?</p>
            <select name="division_id" aria-label="Your division" required
                    class="rounded-lg border border-stone-300 text-sm py-1.5 focus:ring-emerald-500 focus:border-emerald-500">
                @foreach($availableDivisionsForAdd as $division)
                    <option value="{{ $division->id }}" @selected($division->id === $selfDefaultDivisionId)>{{ $division->name }}</option>
                @endforeach
            </select>
            <button type="submit"
                    class="ml-auto px-4 py-1.5 rounded-lg bg-emerald-700 text-white text-sm font-semibold hover:bg-emerald-800 transition shadow-sm">
                Add me
            </button>
        </form>
    @endif

    {{-- Search box: reuses the sponsor-flow endpoint so results include
         each shooter's current entry state on this match. --}}
    <div class="mt-4 relative">
        <input type="search"
               x-model="query"
               @input.debounce.300ms="search()"
               placeholder="Search by name or SAPRF number (min 2 characters)"
               class="w-full rounded-lg border border-stone-300 bg-white text-sm py-2.5 pr-9 focus:ring-emerald-500 focus:border-emerald-500" />
        <div x-show="loading" x-cloak class="absolute right-3 top-1/2 -translate-y-1/2 text-xs text-stone-400">…</div>
    </div>

    <div x-show="results.length > 0 && !selected && !showNewShooter" x-cloak class="mt-3 space-y-2">
        <template x-for="r in results" :key="r.id">
            <div class="flex items-center justify-between gap-2 rounded-lg border border-stone-200 bg-white p-2.5">
                <div class="min-w-0">
                    <p class="text-sm font-medium text-stone-900" x-text="r.saprf_number ? r.name + ' (' + r.saprf_number + ')' : r.name"></p>
                    <p class="text-[11px] text-stone-500" x-show="r.province" x-text="r.province"></p>
                </div>
                <div class="shrink-0">
                    <template x-if="r.entry_state === 'none' || r.entry_state === 'cancelled'">
                        <button type="button"
                                @click="pick(r)"
                                class="px-3 py-1.5 rounded-lg bg-emerald-700 text-white text-xs font-semibold hover:bg-emerald-800 transition">
                            Select
                        </button>
                    </template>
                    <template x-if="r.entry_state === 'unpaid'">
                        <span class="text-[11px] text-amber-700 font-semibold">Unpaid entry exists</span>
                    </template>
                    <template x-if="r.entry_state === 'paid'">
                        <span class="text-[11px] text-emerald-700 font-semibold">Already entered</span>
                    </template>
                </div>
            </div>
        </template>
    </div>

    <p x-show="!loading && query.length >= 2 && results.length === 0 && !selected && !showNewShooter" x-cloak
       class="mt-3 text-xs text-stone-500">No matching members found.</p>

    {{-- Escape hatch: shooter isn't on the platform yet. --}}
    <div class="mt-3 pt-3 border-t border-emerald-100"
         x-show="!selected && !showNewShooter" x-cloak>
        <button type="button"
                @click="startNewShooter()"
                class="text-xs font-medium text-emerald-700 hover:text-emerald-800">
            Can't find them? Add a shooter who isn't on the platform →
        </button>
    </div>

    <template x-if="selected || showNewShooter">
        <form method="POST" action="{{ route('matches.entries.store', $match) }}" class="mt-4 space-y-4">
            @csrf
            <template x-if="selected">
                <input type="hidden" name="user_id" :value="selected.id" />
            </template>
            <template x-if="showNewShooter">
                <div class="contents">
                    <input type="hidden" name="new_shooter_name" :value="newShooterName" />
                    <input type="hidden" name="new_shooter_email" :value="newShooterEmail" />
                </div>
            </template>

            <template x-if="selected">
                <div class="flex items-center justify-between gap-3 rounded-lg border border-stone-200 bg-white p-3">
                    <div class="min-w-0">
                        <p class="text-sm font-semibold text-stone-900" x-text="selected.saprf_number ? selected.name + ' (' + selected.saprf_number + ')' : selected.name"></p>
                        <p class="text-[11px] text-stone-500" x-show="selected.province" x-text="selected.province"></p>
                    </div>
                    <button type="button" @click="clear()"
                            class="text-xs font-medium text-stone-500 hover:text-stone-700">Change shooter</button>
                </div>
            </template>

            <template x-if="showNewShooter">
                <div class="rounded-lg border border-emerald-200 bg-white p-3 space-y-3">
                    <div class="flex items-center justify-between gap-3">
                        <p class="text-sm font-semibold text-stone-900">Adding a new shooter</p>
                        <button type="button" @click="clear()"
                                class="text-xs font-medium text-stone-500 hover:text-stone-700">Cancel</button>
                    </div>
                    <p class="text-[11px] text-stone-500">
                        An unclaimed account is created for them. If you provide an email they can claim it later via Forgot Password.
                    </p>
                    <div>
                        <label for="admin_new_shooter_name" class="block text-xs font-medium text-stone-600 mb-1">Shooter's full name <span class="text-red-500">*</span></label>
                        <input type="text" id="admin_new_shooter_name" x-model.trim="newShooterName"
                               required minlength="2" maxlength="100"
                               placeholder="e.g. Jane Doe"
                               class="w-full rounded-lg border border-stone-300 bg-white text-sm py-2.5 focus:ring-emerald-500 focus:border-emerald-500" />
                    </div>
                    <div>
                        <label for="admin_new_shooter_email" class="block text-xs font-medium text-stone-600 mb-1">Shooter's email <span class="text-stone-400 font-normal">(optional)</span></label>
                        <input type="email" id="admin_new_shooter_email" x-model.trim="newShooterEmail" maxlength="150"
                               placeholder="jane@example.com"
                               class="w-full rounded-lg border border-stone-300 bg-white text-sm py-2.5 focus:ring-emerald-500 focus:border-emerald-500" />
                    </div>
                </div>
            </template>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label for="add_division_id" class="block text-sm font-medium text-stone-700 mb-1">Division <span class="text-red-500">*</span></label>
                    <select name="division_id" id="add_division_id" required
                            class="block w-full rounded-lg border border-stone-300 text-sm focus:ring-emerald-500 focus:border-emerald-500">
                        <option value="" disabled selected>— Select division —</option>
                        @foreach($availableDivisionsForAdd as $division)
                            <option value="{{ $division->id }}">{{ $division->name }}</option>
                        @endforeach
                    </select>
                </div>
            </div>

            <template x-if="showNewShooter || (selected && selected.fee_category === 'non_member')">
                <div class="rounded-lg border border-stone-200 bg-stone-50 p-3 text-sm text-stone-600">
                    Non-member surcharge is kept by SAPRF. Match directors cannot waive it.
                </div>
            </template>

            <template x-if="selected && selected.fee_category === 'lapsed_member'">
                <div class="rounded-lg border border-amber-200 bg-amber-50/60 p-3"
                     x-data="{ waive: false }">
                    <label class="flex items-start gap-2 text-sm text-stone-700">
                        <input type="hidden" name="waive_lapsed_surcharge" value="0">
                        <input type="checkbox" name="waive_lapsed_surcharge" value="1" x-model="waive"
                               class="mt-0.5 rounded border border-stone-300 text-emerald-600 focus:ring-emerald-500">
                        <span>
                            <span class="block font-medium">Waive lapsed-member surcharge</span>
                            <span class="mt-0.5 block text-xs text-stone-500">Only for a membership that expired recently. The non-member surcharge cannot be waived.</span>
                        </span>
                    </label>
                    <div x-show="waive" x-cloak class="mt-3">
                        <label for="fee_override_reason" class="block text-xs font-medium text-stone-600 mb-1">Reason <span class="text-red-500">*</span></label>
                        <textarea name="fee_override_reason" id="fee_override_reason" rows="2" maxlength="500"
                                  :required="waive"
                                  placeholder="e.g. Paid R550 direct to MD in cash; grace on lapsed renewal for this match."
                                  class="block w-full rounded-lg border border-stone-300 text-sm focus:ring-amber-500 focus:border-amber-500"></textarea>
                        <p class="mt-1 text-[11px] text-stone-500">Stored on the entry so future auditors can see why the surcharge was waived.</p>
                    </div>
                </div>
            </template>

            <div class="flex items-center justify-end gap-3 pt-1">
                <button type="button" @click="clear()"
                        class="text-sm text-stone-500 hover:text-stone-700">Cancel</button>
                <button type="submit"
                        :disabled="showNewShooter && newShooterName.trim().length < 2"
                        class="px-4 py-2 rounded-lg bg-emerald-700 text-white text-sm font-semibold hover:bg-emerald-800 disabled:opacity-40 disabled:cursor-not-allowed transition shadow-sm">
                    Add &amp; Confirm as Paid
                </button>
            </div>
        </form>
    </template>

    @if($errors->hasAny(['user_id', 'division_id', 'fee_override_reason', 'waive_lapsed_surcharge', 'new_shooter_name', 'new_shooter_email']))
        <div class="mt-4 rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">
            <ul class="list-inside list-disc space-y-1">
                @foreach(['user_id', 'new_shooter_name', 'new_shooter_email', 'division_id', 'fee_override_reason', 'waive_lapsed_surcharge'] as $field)
                    @foreach($errors->get($field) as $message)
                        <li>{{ $message }}</li>
                    @endforeach
                @endforeach
            </ul>
        </div>
    @endif
</div>

@include('matches.workspace._add-shooter-panel-script')
