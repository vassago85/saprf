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

                <form method="POST" action="{{ url('/events/' . $match->id . '/register') }}" class="p-6 space-y-6">
                    @csrf

                    {{-- Register-as Selector (only shown when the user manages family members) --}}
                    @if(isset($juniors) && $juniors->isNotEmpty())
                        @php $registerUrl = url('/events/' . $match->id . '/register'); @endphp
                        <div class="rounded-xl border border-emerald-200 bg-emerald-50/50 p-4">
                            <label for="for_user" class="block text-sm font-semibold text-stone-900 mb-2">Registering for</label>
                            <select id="for_user"
                                    data-register-url="{{ $registerUrl }}"
                                    onchange="window.location.href = this.dataset.registerUrl + (this.value ? '?for_user=' + this.value : '')"
                                    class="w-full rounded-lg border-stone-300 bg-white text-sm py-2.5 focus:ring-emerald-500 focus:border-emerald-500">
                                <option value="" @selected(!request('for_user') && $shooter->id === auth()->id())>Myself ({{ auth()->user()->name }})</option>
                                @foreach($juniors as $j)
                                    <option value="{{ $j->id }}" @selected((string) request('for_user') === (string) $j->id)>
                                        {{ $j->name }}@if($j->managed_relationship) ({{ $j->managed_relationship }})@endif
                                    </option>
                                @endforeach
                            </select>
                            {{-- Carry the selected for_user through to POST submission --}}
                            <input type="hidden" name="for_user" value="{{ request('for_user') }}">
                            <p class="mt-1.5 text-xs text-stone-500">Choose whether this entry is for yourself or a family member you manage — you'll pay for it from your account.</p>
                        </div>
                    @endif

                    {{-- Pricing Display --}}
                    <div class="rounded-xl border border-stone-200 p-4 space-y-3">
                        <h3 class="text-sm font-semibold text-stone-700">{{ isset($shooter) && $shooter->id !== auth()->id() ? 'Family Member Registration' : 'Your Registration' }}</h3>
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-sm text-stone-600">{{ ($shooter ?? auth()->user())->name }}</p>
                                <p class="text-xs text-stone-400 capitalize">{{ str_replace('_', ' ', $pricing['category']) }}</p>
                            </div>
                            <div class="text-right">
                                <p class="text-lg font-bold {{ $pricing['category'] === 'active_member' ? 'text-emerald-700' : 'text-stone-900' }}">
                                    R {{ number_format($pricing['fee'], 2) }}
                                </p>
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
                            <li>Full payment of <strong>R {{ number_format($pricing['fee'], 2) }}</strong> is required to confirm your entry.</li>
                            <li>Withdrawals made <strong>{{ $withdrawalHours }}+ hours</strong> before the match: refund minus <strong>R {{ number_format($withdrawalFee, 2) }}</strong> admin fee.</li>
                            <li>Withdrawals made <strong>less than {{ $withdrawalHours }} hours</strong> before the match: <strong>no refund</strong>.</li>
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
                            Register &amp; Pay — R {{ number_format($pricing['fee'], 2) }}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

</x-layouts.public>
