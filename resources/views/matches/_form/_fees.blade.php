{{--
    Section 4 of 7 — Fees.
    On the create page we only render the entry fee + junior fee (passed
    via $createMode = true). On settings we render the full fees block,
    including the club-entry opt-in and the Exco/Developer overrides in
    a collapsed <details> disclosure visible only to roles that can see
    them today.

    Field names, validation rules and controller fallbacks are unchanged.
--}}
@php
    /** @var \App\Models\MatchEvent|null $match */
    $match = $match ?? null;
    $lockedByPaidEntries = $lockedByPaidEntries ?? false;
    $paidCount = $counts['paid'] ?? 0;
    $createMode = $createMode ?? false;

    $user = auth()->user();
    $canSeeFeeOverrides = ! $createMode && $user?->hasAnyRole(['exco', 'developer', 'owner', 'admin']);
    $canEditFeeOverrides = ! $createMode && $user?->hasAnyRole(['exco', 'developer']);

    $settings = $settings ?? [];
    $globalPlatformType = $settings['platform_fee_type'] ?? 'fixed';
    $globalPlatformValue = (float) ($settings['platform_fee_value'] ?? 0);
    $globalSaprfType = $settings['saprf_fee_type'] ?? 'fixed';
    $globalSaprfValue = (float) ($settings['saprf_fee_value'] ?? 50);
    $formatGlobal = fn ($type, $value) => $type === 'fixed'
        ? 'R ' . number_format((float) $value, 2) . ' per shooter'
        : rtrim(rtrim(number_format((float) $value, 2), '0'), '.') . '% of match fee';

    $entryFee = old('active_member_fee', $match?->active_member_fee);
    $juniorFee = old('junior_fee', $match?->junior_fee);
    $clubFee = old('club_entry_fee', $match?->club_entry_fee);
    $clubEnabled = old('club_entry_enabled', (bool) ($match?->club_entry_enabled ?? false));

    $defaultSeriesLevel = $match?->series_level;
    $showClubEntryToggle = ! $createMode && in_array(old('series_level', $defaultSeriesLevel), ['club', 'provincial'], true);
@endphp
<section id="fees" class="scroll-mt-24 rounded-xl border border-stone-200 bg-white p-5">
    <header>
        <h3 class="text-sm font-semibold text-stone-900">Fees</h3>
        <p class="mt-0.5 text-xs text-stone-500">What shooters are charged. Already-registered shooters keep what they paid when you change these.</p>
    </header>

    <div class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2">
        <div>
            <label for="active_member_fee" class="block text-xs font-medium text-stone-600 mb-1">
                Match entry fee (ZAR) <span class="text-red-500">*</span>
                @if($lockedByPaidEntries)
                    @include('matches._form._locked-badge', ['paidCount' => $paidCount])
                @endif
            </label>
            <input type="number" name="active_member_fee" id="active_member_fee" step="0.01" min="0"
                   value="{{ $entryFee }}" required
                   class="block w-full rounded-lg border border-stone-300 text-sm focus:border-emerald-500 focus:ring-emerald-500" />
            <p class="mt-1 text-xs text-stone-400">Charged to active SAPRF members on sign-up.</p>
            @error('active_member_fee') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
        </div>

        <div>
            <label for="junior_fee" class="block text-xs font-medium text-stone-600 mb-1">Junior fee (ZAR)</label>
            <input type="number" name="junior_fee" id="junior_fee" step="0.01" min="0" value="{{ $juniorFee }}"
                   placeholder="Blank = same as entry fee"
                   class="block w-full rounded-lg border border-stone-300 text-sm focus:border-emerald-500 focus:ring-emerald-500" />
            <p class="mt-1 text-xs text-stone-400">Charged for Junior-division entries.</p>
            @error('junior_fee') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
        </div>

        @if($showClubEntryToggle)
            {{-- Club shooters: opt-in on club/provincial matches. Off = non-members
                 pay the member fee plus the non-member surcharge. On = non-members
                 land on the club entry bracket with no surcharge and the club
                 price below. --}}
            <div class="sm:col-span-2 rounded-lg border border-stone-200 bg-stone-50/60 p-4"
                 x-data="{ enabled: {{ $clubEnabled ? 'true' : 'false' }} }">
                <input type="hidden" name="club_entry_enabled" value="0" />
                <label class="flex items-start gap-3 cursor-pointer">
                    <input type="checkbox" name="club_entry_enabled" value="1" x-model="enabled"
                           class="mt-0.5 rounded border-stone-300 text-emerald-600 focus:ring-emerald-500" />
                    <span class="text-sm text-stone-700">
                        <span class="block text-sm font-medium text-stone-900">Allow non-members to enter as club shooters</span>
                        <span class="mt-0.5 block text-xs text-stone-500">
                            Combines this {{ $match?->series_level }} match with a club match: non-members pay the club price below with <strong>no non-member surcharge</strong>. Leave off and non-members pay the member fee plus the non-member surcharge.
                        </span>
                    </span>
                </label>

                <div x-show="enabled" x-cloak class="mt-4">
                    <label for="club_entry_fee" class="block text-xs font-medium text-stone-600 mb-1">Club entry fee (ZAR)</label>
                    <input type="number" name="club_entry_fee" id="club_entry_fee" step="0.01" min="0" value="{{ $clubFee }}"
                           placeholder="Blank = same as entry fee"
                           class="block w-full rounded-lg border border-stone-300 text-sm focus:border-emerald-500 focus:ring-emerald-500" />
                    <p class="mt-1 text-xs text-stone-400">Leave blank and club shooters pay the same as members. SAPRF and the platform each take a small percentage of the fee when the shooter pays through PayFast — nothing when you collect the money yourself.</p>
                    @error('club_entry_fee') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>
            </div>
        @endif

        @if($canSeeFeeOverrides)
            <details class="sm:col-span-2 rounded-lg border border-stone-200 bg-stone-50 p-4 group">
                <summary class="flex cursor-pointer items-center justify-between gap-2 text-sm font-semibold text-stone-800">
                    <span>
                        Fee overrides
                        <span class="ml-2 inline-flex rounded-full bg-stone-200 px-2 py-0.5 text-[10px] font-semibold uppercase text-stone-600">
                            {{ $canEditFeeOverrides ? 'Exco / Developer' : 'Read-only' }}
                        </span>
                    </span>
                    <flux:icon.chevron-down class="size-4 text-stone-400 transition-transform group-open:rotate-180" />
                </summary>

                <p class="mt-2 text-xs text-stone-500">
                    Leave blank to inherit the global rate. Override to charge a different SAPRF or platform fee for this match — e.g. R0 for matches not running through the platform.
                </p>

                <div class="mt-3 grid grid-cols-1 gap-4 sm:grid-cols-2">
                    {{-- Platform fee override --}}
                    <div x-data="{
                            type: '{{ old('platform_fee_type', $match?->platform_fee_type ?? '') }}',
                            value: '{{ old('platform_fee_value', $match?->platform_fee_value ?? '') }}',
                        }">
                        <label class="block text-xs font-medium text-stone-600 mb-1">Platform fee</label>
                        <div class="flex gap-2">
                            <select name="platform_fee_type" x-model="type" @change="if (!type) value = ''"
                                    @disabled(!$canEditFeeOverrides)
                                    class="rounded-lg border border-stone-300 py-2 text-sm focus:border-emerald-500 focus:ring-emerald-500 disabled:bg-stone-100 disabled:text-stone-500">
                                <option value="">Inherit ({{ $formatGlobal($globalPlatformType, $globalPlatformValue) }})</option>
                                <option value="fixed">R fixed / shooter</option>
                                <option value="percentage">% of match fee</option>
                            </select>
                            <div class="relative flex-1">
                                <input type="number" name="platform_fee_value" step="0.01" min="0" x-model="value"
                                       :required="!!type"
                                       :disabled="!type || {{ $canEditFeeOverrides ? 'false' : 'true' }}"
                                       placeholder="—"
                                       class="block w-full rounded-lg border border-stone-300 py-2 pr-8 text-sm focus:border-emerald-500 focus:ring-emerald-500 disabled:bg-stone-100 disabled:text-stone-400">
                                <span class="pointer-events-none absolute inset-y-0 right-3 flex items-center text-xs font-semibold text-stone-400" x-text="type === 'percentage' ? '%' : (type === 'fixed' ? 'R' : '')"></span>
                            </div>
                        </div>
                        @error('platform_fee_type') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                        @error('platform_fee_value') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>

                    {{-- SAPRF fee override --}}
                    <div x-data="{
                            type: '{{ old('saprf_fee_type', $match?->saprf_fee_type ?? '') }}',
                            value: '{{ old('saprf_fee_value', $match?->saprf_fee_value ?? '') }}',
                        }">
                        <label class="block text-xs font-medium text-stone-600 mb-1">SAPRF fee</label>
                        <div class="flex gap-2">
                            <select name="saprf_fee_type" x-model="type" @change="if (!type) value = ''"
                                    @disabled(!$canEditFeeOverrides)
                                    class="rounded-lg border border-stone-300 py-2 text-sm focus:border-emerald-500 focus:ring-emerald-500 disabled:bg-stone-100 disabled:text-stone-500">
                                <option value="">Inherit ({{ $formatGlobal($globalSaprfType, $globalSaprfValue) }})</option>
                                <option value="fixed">R fixed / shooter</option>
                                <option value="percentage">% of match fee</option>
                            </select>
                            <div class="relative flex-1">
                                <input type="number" name="saprf_fee_value" step="0.01" min="0" x-model="value"
                                       :required="!!type"
                                       :disabled="!type || {{ $canEditFeeOverrides ? 'false' : 'true' }}"
                                       placeholder="—"
                                       class="block w-full rounded-lg border border-stone-300 py-2 pr-8 text-sm focus:border-emerald-500 focus:ring-emerald-500 disabled:bg-stone-100 disabled:text-stone-400">
                                <span class="pointer-events-none absolute inset-y-0 right-3 flex items-center text-xs font-semibold text-stone-400" x-text="type === 'percentage' ? '%' : (type === 'fixed' ? 'R' : '')"></span>
                            </div>
                        </div>
                        @error('saprf_fee_type') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                        @error('saprf_fee_value') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                </div>
            </details>
        @endif
    </div>
</section>
