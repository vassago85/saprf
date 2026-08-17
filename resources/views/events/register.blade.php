<x-layouts.public :title="'Register - ' . $match->name . ' - SAPRF'" current="events">
    <div class="bg-stone-50 min-h-screen">
        <div class="max-w-2xl mx-auto px-4 sm:px-6 py-10">
            {{-- Back link --}}
            <a href="{{ url('/events/' . $match->id) }}"
               class="inline-flex items-center gap-1.5 text-sm text-stone-500 hover:text-emerald-700 transition mb-6">
                <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5 8.25 12l7.5-7.5" /></svg>
                Back to Event
            </a>

            {{-- Match Summary --}}
            <div class="bg-white rounded-2xl border border-stone-200 shadow-sm p-6 mb-6">
                <div class="flex items-start gap-4">
                    <x-date-badge :date="$match->match_date" :end-date="$match->match_end_date" :compact="true" />
                    <div class="flex-1 min-w-0">
                        <div class="flex flex-wrap gap-2 mb-2">
                            <x-discipline-chip :discipline="$match->match_type" />
                            <x-level-chip :level="$match->series_level" />
                        </div>
                        <h1 class="font-heading text-xl font-bold text-stone-900">{{ $match->name }}</h1>
                        <p class="text-sm text-stone-500 mt-0.5">{{ $match->location_display ?: $match->venue_name }}</p>
                    </div>
                </div>
            </div>

            {{-- Registration Form --}}
            <div class="bg-white rounded-2xl border border-stone-200 shadow-sm overflow-hidden">
                <div class="px-6 py-4 border-b border-stone-100">
                    <h2 class="text-lg font-semibold text-stone-900">Complete Registration</h2>
                </div>

                <form method="POST" action="{{ url('/events/' . $match->id . '/register') }}" class="p-6 space-y-6"
                    @if(! empty($juniorPricing))
                        x-data="{
                            selectedDivision: '{{ old('division_id') }}',
                            adultFee: {{ (float) $pricing['fee'] }},
                            juniorFee: {{ (float) $juniorPricing['fee'] }},
                            juniorDivisionId: '{{ $juniorDivisionId }}',
                            get fee() { return this.selectedDivision === this.juniorDivisionId ? this.juniorFee : this.adultFee; },
                            get feeText() { return 'R ' + this.fee.toFixed(2); },
                        }"
                    @endif
                    >
                    @csrf

                    @php
                        $registerUrl = url('/events/' . $match->id . '/register');
                        $isNewShooter = $isNewShooter ?? false;
                        // Distinguish "family member" (managed junior owned by the actor)
                        // from a sponsored member (any other independent member).
                        // A brand-new shooter (not on the platform yet) counts as
                        // sponsored — the actor is entering + paying for someone
                        // whose account is about to be provisioned on POST.
                        $isSponsoredEntry = $isNewShooter
                            || ($shooter->id !== auth()->id()
                                && ! ($shooter->is_managed_account && $shooter->parent_id === auth()->id()));
                        $isFamilyEntry = ! $isNewShooter && $shooter->id !== auth()->id() && ! $isSponsoredEntry;
                        $sectionHeading = match (true) {
                            $isNewShooter => 'New Shooter Registration',
                            $isSponsoredEntry => 'Sponsored Registration',
                            $isFamilyEntry => 'Family Member Registration',
                            default => 'Your Registration',
                        };
                    @endphp

                    {{-- Register-as Selector (only shown when the user manages family members) --}}
                    @if(isset($juniors) && $juniors->isNotEmpty())
                        <div class="rounded-xl border border-emerald-200 bg-emerald-50/50 p-4">
                            <label for="for_user" class="block text-sm font-semibold text-stone-900 mb-2">Registering for</label>
                            <select id="for_user"
                                    data-register-url="{{ $registerUrl }}"
                                    onchange="window.location.href = this.dataset.registerUrl + (this.value ? '?for_user=' + this.value : '')"
                                    class="w-full rounded-lg border-stone-300 bg-white text-sm py-2.5 focus:ring-emerald-500 focus:border-emerald-500">
                                <option value="" @selected(!$isSponsoredEntry && $shooter->id === auth()->id())>Myself ({{ auth()->user()->name }})</option>
                                @foreach($juniors as $j)
                                    <option value="{{ $j->id }}" @selected(! $isSponsoredEntry && (string) $shooter->id === (string) $j->id)>
                                        {{ $j->name }}@if($j->managed_relationship) ({{ $j->managed_relationship }})@endif
                                    </option>
                                @endforeach
                            </select>
                            <p class="mt-1.5 text-xs text-stone-500">Choose whether this entry is for yourself or a family member you manage — you'll pay for it from your account.</p>
                        </div>
                    @endif
                    {{-- Carry the resolved shooter (family or sponsored member) through to POST. --}}
                    @if($isNewShooter)
                        {{-- No for_user; the backend provisions the stub from the
                             name/email fields on POST via GuestShooterService. --}}
                        <input type="hidden" name="new_shooter_name" value="{{ $shooter->name }}">
                        <input type="hidden" name="new_shooter_email" value="{{ $shooter->email }}">
                    @else
                        <input type="hidden" name="for_user" value="{{ $shooter->id === auth()->id() ? '' : $shooter->id }}">
                    @endif

                    {{-- Sponsor entry (search any other member by name or SAPRF number) --}}
                    <div id="sponsor" class="rounded-xl border border-sky-200 bg-sky-50/50 p-4"
                         x-data="sponsorSearch({
                            searchUrl: '{{ route('events.members.search', $match) }}',
                            registerUrl: '{{ $registerUrl }}',
                            payUrlTemplate: '{{ url('/payments/registration') }}/__ID__',
                            csrf: '{{ csrf_token() }}',
                         })"
                         x-init="init()">
                        <div class="flex items-start justify-between gap-3">
                            <div>
                                <h3 class="text-sm font-semibold text-stone-900">Enter or pay for someone else</h3>
                                <p class="text-xs text-stone-500 mt-0.5">Sponsor another shooter by name or SAPRF number. You'll pay from your account.</p>
                            </div>
                            @if($isSponsoredEntry)
                                <a href="{{ $registerUrl }}"
                                   class="text-xs font-medium text-sky-700 hover:text-sky-800">Cancel sponsor</a>
                            @endif
                        </div>

                        <div class="mt-3 relative" x-show="!showNewShooter" x-cloak>
                            <input type="search"
                                   x-model.debounce.300ms="query"
                                   @input="search()"
                                   placeholder="Name or SAPRF number (min 2 characters)"
                                   class="w-full rounded-lg border border-stone-300 bg-white text-sm py-2.5 pr-9 focus:ring-sky-500 focus:border-sky-500" />
                            <div x-show="loading" class="absolute right-3 top-1/2 -translate-y-1/2 text-xs text-stone-400">…</div>
                        </div>

                        <div x-show="results.length > 0 && !showNewShooter" x-cloak class="mt-3 space-y-2">
                            <template x-for="r in results" :key="r.id">
                                <div class="flex items-center justify-between gap-2 rounded-lg border border-stone-200 bg-white p-2.5">
                                    <div class="min-w-0">
                                        <p class="text-sm font-medium text-stone-900" x-text="r.name"></p>
                                        <p class="text-[11px] text-stone-500">
                                            <span x-text="r.saprf_number ? 'SAPRF ' + r.saprf_number : 'No SAPRF number'"></span>
                                            <template x-if="r.province"><span> · <span x-text="r.province"></span></span></template>
                                        </p>
                                    </div>
                                    <div class="shrink-0">
                                        <template x-if="r.entry_state === 'none' || r.entry_state === 'cancelled'">
                                            <button type="button"
                                                    @click="pickSponsor(r)"
                                                    class="px-3 py-1.5 rounded-lg bg-sky-700 text-white text-xs font-semibold hover:bg-sky-800 transition">
                                                Enter &amp; Pay
                                            </button>
                                        </template>
                                        <template x-if="r.entry_state === 'unpaid'">
                                            <form method="POST" :action="payUrlFor(r)" class="m-0">
                                                <input type="hidden" name="_token" :value="csrf" />
                                                <button type="submit"
                                                        class="px-3 py-1.5 rounded-lg bg-emerald-700 text-white text-xs font-semibold hover:bg-emerald-800 transition">
                                                    Pay Entry
                                                </button>
                                            </form>
                                        </template>
                                        <template x-if="r.entry_state === 'paid'">
                                            <span class="text-[11px] text-emerald-700 font-semibold">Already paid</span>
                                        </template>
                                    </div>
                                </div>
                            </template>
                        </div>

                        <p x-show="!loading && query.length >= 2 && results.length === 0 && !showNewShooter" x-cloak
                           class="mt-3 text-xs text-stone-500">No matching members found.</p>

                        {{-- Escape hatch: sponsor a shooter who isn't on the
                             platform yet. Reloads the register page in
                             "new shooter" mode so the pricing + division
                             picker reflect a non-member entry. --}}
                        <div class="mt-3 pt-3 border-t border-sky-100" x-show="!showNewShooter" x-cloak>
                            <button type="button"
                                    @click="showNewShooter = true"
                                    class="text-xs font-medium text-sky-700 hover:text-sky-800">
                                Can't find them? Enter a shooter who isn't on the platform →
                            </button>
                        </div>

                        <div x-show="showNewShooter" x-cloak class="mt-3 space-y-3">
                            <p class="text-xs text-stone-600">
                                We'll create an unclaimed account for them. If you provide their email they can claim it later via Forgot Password.
                            </p>
                            <div>
                                <label for="new_shooter_name_input" class="block text-xs font-medium text-stone-600 mb-1">Shooter's full name <span class="text-red-500">*</span></label>
                                <input type="text" id="new_shooter_name_input" x-model.trim="newShooterName" required minlength="2" maxlength="100"
                                       placeholder="e.g. Jane Doe"
                                       class="w-full rounded-lg border border-stone-300 bg-white text-sm py-2.5 focus:ring-sky-500 focus:border-sky-500" />
                            </div>
                            <div>
                                <label for="new_shooter_email_input" class="block text-xs font-medium text-stone-600 mb-1">Shooter's email <span class="text-stone-400 font-normal">(optional)</span></label>
                                <input type="email" id="new_shooter_email_input" x-model.trim="newShooterEmail" maxlength="150"
                                       placeholder="jane@example.com"
                                       class="w-full rounded-lg border border-stone-300 bg-white text-sm py-2.5 focus:ring-sky-500 focus:border-sky-500" />
                                <p class="mt-1 text-[11px] text-stone-400">Used to send the confirmation email and let them log in later.</p>
                            </div>
                            <div class="flex items-center justify-between pt-1">
                                <button type="button" @click="showNewShooter = false; newShooterName = ''; newShooterEmail = ''"
                                        class="text-xs text-stone-500 hover:text-stone-700">Back to search</button>
                                <button type="button"
                                        @click="pickNewShooter()"
                                        :disabled="newShooterName.trim().length < 2"
                                        class="px-3 py-1.5 rounded-lg bg-sky-700 text-white text-xs font-semibold hover:bg-sky-800 disabled:opacity-40 disabled:cursor-not-allowed transition">
                                    Continue →
                                </button>
                            </div>
                        </div>
                    </div>
                    <script>
                        document.addEventListener('alpine:init', () => {
                            Alpine.data('sponsorSearch', (config) => ({
                                query: '',
                                results: [],
                                loading: false,
                                showNewShooter: false,
                                newShooterName: '',
                                newShooterEmail: '',
                                searchUrl: config.searchUrl,
                                registerUrl: config.registerUrl,
                                payUrlTemplate: config.payUrlTemplate,
                                csrf: config.csrf,
                                _abort: null,
                                init() {},
                                search() {
                                    if (this.query.trim().length < 2) {
                                        this.results = [];
                                        return;
                                    }
                                    if (this._abort) { this._abort.abort(); }
                                    this._abort = new AbortController();
                                    this.loading = true;
                                    fetch(this.searchUrl + '?q=' + encodeURIComponent(this.query.trim()), {
                                        headers: { 'Accept': 'application/json' },
                                        credentials: 'same-origin',
                                        signal: this._abort.signal,
                                    })
                                        .then(r => r.ok ? r.json() : Promise.reject(r))
                                        .then(data => { this.results = data.results || []; })
                                        .catch(() => { /* ignored: transient network / abort */ })
                                        .finally(() => { this.loading = false; });
                                },
                                pickSponsor(member) {
                                    window.location.href = this.registerUrl + '?for_user=' + encodeURIComponent(member.id);
                                },
                                pickNewShooter() {
                                    const name = this.newShooterName.trim();
                                    if (name.length < 2) { return; }
                                    const params = new URLSearchParams({ new_shooter_name: name });
                                    const email = this.newShooterEmail.trim();
                                    if (email) { params.set('new_shooter_email', email); }
                                    window.location.href = this.registerUrl + '?' + params.toString();
                                },
                                payUrlFor(member) {
                                    return this.payUrlTemplate.replace('__ID__', member.registration_id);
                                },
                            }));
                        });
                    </script>

                    {{-- Pricing Display --}}
                    <div class="rounded-xl border border-stone-200 p-4 space-y-3">
                        <h3 class="text-sm font-semibold text-stone-700">{{ $sectionHeading }}</h3>
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-sm text-stone-600">{{ ($shooter ?? auth()->user())->name }}</p>
                                <p class="text-xs text-stone-400 capitalize">{{ str_replace('_', ' ', $pricing['category']) }}</p>
                            </div>
                            <div class="text-right">
                                <p class="text-lg font-bold {{ $pricing['category'] === 'active_member' ? 'text-emerald-700' : 'text-stone-900' }}">
                                    @if(! empty($juniorPricing))<span x-text="feeText"></span>@else R {{ number_format($pricing['fee'], 2) }}@endif
                                </p>
                                @if(! empty($juniorPricing))
                                    <p class="text-[11px] text-stone-400">Junior division: R {{ number_format($juniorPricing['fee'], 2) }}</p>
                                @endif
                                @if($pricing['category'] !== 'active_member')
                                    <p class="text-[11px] text-stone-400">
                                        Member rate: R {{ number_format($match->active_member_fee, 2) }}
                                    </p>
                                @endif
                            </div>
                        </div>
                        @if($pricing['category'] === 'non_member')
                            <p class="text-xs text-amber-600 bg-amber-50 rounded-lg px-3 py-2">
                                Non-member scores may not count towards season standings. Consider becoming a paid SAPRF member for full benefits.
                            </p>
                        @endif
                    </div>

                    {{-- Division (compulsory) --}}
                    <div>
                        <label for="division_id" class="block text-sm font-medium text-stone-700 mb-1.5">Division <span class="text-red-500">*</span></label>
                        <select name="division_id" id="division_id" required
                                @if(! empty($juniorPricing)) x-model="selectedDivision" @endif
                                class="w-full rounded-xl border border-stone-300 text-sm py-2.5 focus:ring-emerald-500 focus:border-emerald-500">
                            <option value="" disabled @selected(!old('division_id'))>— Select a division —</option>
                            @foreach($divisions as $division)
                                <option value="{{ $division->id }}" @selected((string) old('division_id') === (string) $division->id)>
                                    {{ $division->name }}
                                </option>
                            @endforeach
                        </select>
                        <p class="mt-1 text-xs text-stone-400">The equipment class you are competing in. Required.</p>
                    </div>

                    {{-- Rifle Configuration --}}
                    @if($rifles->isNotEmpty())
                        <div>
                            <label for="rifle_configuration_id" class="block text-sm font-medium text-stone-700 mb-1.5">Rifle Configuration</label>
                            <select name="rifle_configuration_id" id="rifle_configuration_id"
                                    class="w-full rounded-xl border border-stone-300 text-sm py-2.5 focus:ring-emerald-500 focus:border-emerald-500">
                                <option value="">— Select rifle (optional)</option>
                                @foreach($rifles as $rifle)
                                    <option value="{{ $rifle->id }}">
                                        {{ $rifle->nickname ?: ($rifle->make?->name . ' ' . $rifle->model?->name) }}
                                        @if($rifle->calibre) — {{ $rifle->calibre->name }} @endif
                                        @if($rifle->is_primary) (Primary) @endif
                                    </option>
                                @endforeach
                            </select>
                            <p class="mt-1 text-xs text-stone-400">Track performance stats per rifle configuration.</p>
                        </div>
                    @elseif($isNewShooter)
                        {{-- The sponsor doesn't pick a rifle for someone else who
                             isn't on the platform yet — the shooter chooses it
                             themselves once they claim the account. --}}
                        <div class="rounded-xl border border-dashed border-sky-300 bg-sky-50/40 p-4 text-center">
                            <p class="text-sm text-stone-600">Rifle configuration will be selected by the shooter once they claim their account.</p>
                        </div>
                    @else
                        <div class="rounded-xl border border-dashed border-stone-300 p-4 text-center">
                            <p class="text-sm text-stone-500">No rifle configurations set up yet.</p>
                            <a href="{{ route('rifle-configurations.create') }}" class="text-xs text-emerald-700 font-medium hover:text-emerald-800">Add a rifle &rarr;</a>
                        </div>
                    @endif

                    {{-- Notes --}}
                    <div>
                        <label for="notes" class="block text-sm font-medium text-stone-700 mb-1.5">Notes <span class="text-stone-400 font-normal">(optional)</span></label>
                        <textarea name="notes" id="notes" rows="3" maxlength="500"
                                  placeholder="Any special requirements, dietary needs, etc."
                                  class="w-full rounded-xl border border-stone-300 text-sm py-2.5 focus:ring-emerald-500 focus:border-emerald-500">{{ old('notes') }}</textarea>
                    </div>

                    {{-- Withdrawal Policy --}}
                    @php
                        $withdrawalFee = (float) app(\App\Services\SettingsService::class)->get('withdrawal_admin_fee', 100);
                        $withdrawalHours = (int) app(\App\Services\SettingsService::class)->get('withdrawal_deadline_hours', 72);
                    @endphp
                    <div class="rounded-xl border border-amber-200 bg-amber-50 p-4 space-y-2">
                        <h4 class="text-sm font-semibold text-amber-800">Cancellation / Withdrawal Policy</h4>
                        <ul class="text-xs text-amber-700 space-y-1 list-disc list-inside">
                            @if(((float) $pricing['fee']) > 0)
                                <li>Full payment of <strong>@if(! empty($juniorPricing))<span x-text="feeText"></span>@else R {{ number_format($pricing['fee'], 2) }}@endif</strong> is required to confirm your entry.</li>
                                <li>Withdrawals made <strong>{{ $withdrawalHours }}+ hours</strong> before the match: refund minus <strong>R {{ number_format($withdrawalFee, 2) }}</strong> admin fee.</li>
                                <li>Withdrawals made <strong>less than {{ $withdrawalHours }} hours</strong> before the match: <strong>no refund</strong>.</li>
                            @else
                                <li>This is a <strong>free entry</strong> — no payment required to confirm.</li>
                                <li>You can withdraw at any time before the match with no financial impact.</li>
                            @endif
                        </ul>
                    </div>

                    {{-- Errors --}}
                    @if($errors->any())
                        <div class="rounded-xl border border-red-200 bg-red-50 p-4">
                            <ul class="text-sm text-red-700 space-y-1">
                                @foreach($errors->all() as $error)
                                    <li>{{ $error }}</li>
                                @endforeach
                            </ul>
                        </div>
                    @endif

                    {{-- Submit --}}
                    <div class="flex items-center justify-between pt-2">
                        <a href="{{ url('/events/' . $match->id) }}" class="text-sm text-stone-500 hover:text-stone-700 transition">Cancel</a>
                        <button type="submit"
                                class="px-6 py-3 rounded-xl bg-emerald-700 text-white font-semibold hover:bg-emerald-800 transition shadow-sm">
                            Register &amp; Pay — @if(! empty($juniorPricing))<span x-text="feeText"></span>@else R {{ number_format($pricing['fee'], 2) }}@endif
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

</x-layouts.public>
