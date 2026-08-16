<x-layouts.app :title="'IPRF Team Selection'">
    <div class="space-y-6">
        <div>
            <p class="text-sm text-stone-500">SAPRF IPRF Team Selection</p>
            <h1 class="font-heading text-3xl font-bold text-stone-900 tracking-tight">Team Selection</h1>
            <p class="mt-2 max-w-2xl text-sm text-stone-600">
                Choose whether you want to be considered for a SAPRF IPRF national team, complete the
                required Eligibility to Compete forms, and see your live eligibility and participation
                status. Submitting the online form here is treated by ExCo as receipt of your form.
            </p>
        </div>

        @if (session('success'))
            <div class="rounded-lg border border-emerald-200 bg-emerald-50 p-3 text-sm text-emerald-800">{{ session('success') }}</div>
        @endif
        @if (session('info'))
            <div class="rounded-lg border border-sky-200 bg-sky-50 p-3 text-sm text-sky-800">{{ session('info') }}</div>
        @endif
        @if (session('error'))
            <div class="rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-800">{{ session('error') }}</div>
        @endif

        @if ($entries->isEmpty())
            <div class="rounded-xl border border-stone-200 bg-white p-8 text-center shadow-sm">
                <p class="text-sm text-stone-500">No IPRF selection cycles are currently open.</p>
                <p class="mt-2 text-xs text-stone-400">Watch the SAPRF documents page for the next cycle's opening date.</p>
            </div>
        @endif

        @foreach ($entries as $entry)
            @php
                $cycle = $entry['cycle'];
                $athlete = $entry['athlete'];
                $criteria = $entry['criteria'];
                $writable = $entry['is_writable'];
                $hasForm = $entry['has_submitted_form'];
                $profileComplete = $entry['profile_complete'];
                $policyRoute = route('selection.policy.public', ['series' => strtolower($cycle->series)]);
            @endphp

            <section class="space-y-4 rounded-xl border border-stone-200 bg-stone-50/60 p-5 shadow-sm">
                <header class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
                    <div>
                        <div class="text-xs font-semibold uppercase tracking-widest text-stone-400">
                            {{ $cycle->series }} · Season {{ $cycle->season }}
                        </div>
                        <h2 class="mt-0.5 text-xl font-bold text-stone-900">{{ $cycle->championship_name ?: ($cycle->series.' '.$cycle->season) }}</h2>
                        <p class="mt-1 text-xs text-stone-500">
                            Declaration deadline: {{ $cycle->declaration_deadline?->format('D, j M Y H:i') ?? '—' }}
                            · Qualifying window: {{ $cycle->qualifying_period_start?->format('j M Y') }} — {{ $cycle->qualifying_period_end?->format('j M Y') }}
                        </p>
                    </div>
                    <div class="flex flex-wrap items-center gap-2">
                        @if ($athlete)
                            <span class="rounded-full bg-stone-800 px-3 py-1 text-xs font-semibold text-white">
                                {{ str_replace('_', ' ', $athlete->state) }}
                            </span>
                        @endif
                        <a href="{{ $policyRoute }}" class="inline-flex items-center gap-1 rounded-lg border border-stone-300 bg-white px-3 py-1 text-xs font-semibold text-stone-700 hover:bg-stone-50">
                            Read policy
                        </a>
                    </div>
                </header>

                @unless ($writable)
                    <div class="rounded-lg border border-amber-200 bg-amber-50 p-3 text-xs text-amber-800">
                        This cycle is no longer accepting online submissions. Contact ExCo directly if you need to make a change.
                    </div>
                @endunless

                {{-- 1. Opt-in card --}}
                @if (! $athlete)
                    <div class="rounded-xl border border-stone-200 bg-white p-5 shadow-sm">
                        <h3 class="text-sm font-semibold text-stone-900">Would you like to be considered?</h3>
                        <p class="mt-1 text-sm text-stone-600">
                            Opting in adds you to the athlete list for this cycle. You can still withdraw later.
                            You will need to complete the Eligibility to Compete form and meet the participation criteria
                            in the policy for selection.
                        </p>
                        @if ($writable)
                            <form method="POST" action="{{ route('iprf.opt-in', $cycle) }}" class="mt-4">
                                @csrf
                                <button type="submit" class="inline-flex items-center gap-2 rounded-lg bg-emerald-700 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-800">
                                    Yes, consider me for {{ $cycle->series }} {{ $cycle->season }}
                                </button>
                            </form>
                        @endif
                    </div>
                @else
                    <div class="rounded-xl border border-stone-200 bg-white p-5 shadow-sm">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div>
                                <h3 class="text-sm font-semibold text-stone-900">You are on the list for this cycle.</h3>
                                <p class="mt-1 text-xs text-stone-500">
                                    Registered {{ $athlete->created_at?->format('j M Y') }}.
                                    Last evaluated {{ $athlete->last_evaluated_at?->format('j M Y H:i') ?? 'not yet' }}.
                                </p>
                            </div>
                            @if ($writable && $athlete->state !== \App\Models\SelectionAthlete::STATE_NOT_SELECTED)
                                <form method="POST" action="{{ route('iprf.withdraw', $cycle) }}"
                                      onsubmit="return confirm('Withdraw from this selection cycle? Your submitted form will be marked withdrawn.');">
                                    @csrf
                                    <button type="submit" class="rounded-lg border border-red-300 bg-white px-3 py-1.5 text-xs font-semibold text-red-700 hover:bg-red-50">
                                        Withdraw
                                    </button>
                                </form>
                            @endif
                        </div>
                    </div>
                @endif

                {{-- 2. Eligibility to Compete form --}}
                <div class="rounded-xl border border-stone-200 bg-white p-5 shadow-sm">
                    <h3 class="text-sm font-semibold text-stone-900">Eligibility to Compete form</h3>
                    <p class="mt-1 text-sm text-stone-600">
                        This is the combined intention-to-participate and Eligibility to Compete
                        declaration required by clause ELG-05 of the policy. Submitting it here is
                        treated by ExCo as receipt of your form.
                    </p>

                    @if ($hasForm)
                        <div class="mt-4 rounded-lg border border-emerald-200 bg-emerald-50 p-3 text-sm text-emerald-800">
                            <p class="font-semibold">Form submitted.</p>
                            <p class="mt-1 text-xs">
                                Submitted {{ $athlete->declaration?->submitted_at?->format('j M Y H:i') }} —
                                signed as <span class="font-mono">{{ $athlete->declaration?->form_data['signature'] ?? $user->name }}</span>.
                                ExCo has been notified.
                            </p>
                        </div>
                    @elseif (! $writable)
                        <p class="mt-3 text-xs text-stone-500">Form submissions are closed for this cycle.</p>
                    @elseif (! $profileComplete)
                        <div class="mt-4 rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-800">
                            Please <a href="{{ route('profile') }}" class="font-semibold underline">complete your profile</a>
                            (South African citizenship and country of residence) before submitting this form. We
                            can't evaluate your eligibility without those.
                        </div>
                    @else
                        <form method="POST" action="{{ route('iprf.form', $cycle) }}" class="mt-4 space-y-4">
                            @csrf

                            @if ($errors->any())
                                <div class="rounded-lg border border-red-200 bg-red-50 p-3 text-xs text-red-800">
                                    <ul class="list-disc pl-4 space-y-0.5">
                                        @foreach ($errors->all() as $error)
                                            <li>{{ $error }}</li>
                                        @endforeach
                                    </ul>
                                </div>
                            @endif

                            <div class="space-y-2 text-sm text-stone-700">
                                <label class="flex items-start gap-3">
                                    <input type="checkbox" name="intention_to_participate" value="1" class="mt-0.5 rounded border-stone-300 text-emerald-600 focus:ring-emerald-500" @checked(old('intention_to_participate'))>
                                    <span>I declare my intention to participate in the {{ $cycle->championship_name ?: ($cycle->series.' '.$cycle->season) }}.</span>
                                </label>
                                <label class="flex items-start gap-3">
                                    <input type="checkbox" name="able_and_willing" value="1" class="mt-0.5 rounded border-stone-300 text-emerald-600 focus:ring-emerald-500" @checked(old('able_and_willing'))>
                                    <span>I am able and willing to undertake the matches, training and competition programme
                                        recommended by ExCo and the Selectors, unless a written exemption is granted.</span>
                                </label>
                                <label class="flex items-start gap-3">
                                    <input type="checkbox" name="satisfy_preconditions" value="1" class="mt-0.5 rounded border-stone-300 text-emerald-600 focus:ring-emerald-500" @checked(old('satisfy_preconditions'))>
                                    <span>I will satisfy any additional preconditions advised in writing by ExCo
                                        prior to or at the time of selection.</span>
                                </label>
                                <label class="flex items-start gap-3">
                                    <input type="checkbox" name="no_impairment" value="1" class="mt-0.5 rounded border-stone-300 text-emerald-600 focus:ring-emerald-500" @checked(old('no_impairment'))>
                                    <span>I confirm that I am not suffering any physical or mental impairment that may
                                        prevent me from competing at the highest possible standard.</span>
                                </label>
                            </div>

                            <div>
                                <label for="signature_{{ $cycle->id }}" class="block text-xs font-semibold text-stone-600">
                                    Type your full name as your signature <span class="text-red-500">*</span>
                                </label>
                                <input type="text" id="signature_{{ $cycle->id }}" name="signature" required
                                       value="{{ old('signature') }}"
                                       placeholder="{{ $user->name }}"
                                       class="mt-1 block w-full rounded-lg border border-stone-300 text-sm focus:ring-emerald-500 focus:border-emerald-500">
                                <p class="mt-1 text-xs text-stone-400">Must match the name on your SAPRF account: <span class="font-mono">{{ $user->name }}</span></p>
                            </div>

                            <div>
                                <label for="notes_{{ $cycle->id }}" class="block text-xs font-semibold text-stone-600">Optional notes for ExCo</label>
                                <textarea id="notes_{{ $cycle->id }}" name="notes" rows="3"
                                          class="mt-1 block w-full rounded-lg border border-stone-300 text-sm focus:ring-emerald-500 focus:border-emerald-500">{{ old('notes') }}</textarea>
                            </div>

                            <button type="submit" class="inline-flex items-center gap-2 rounded-lg bg-emerald-700 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-800">
                                Submit Eligibility to Compete form
                            </button>
                        </form>
                    @endif
                </div>

                {{-- 3. Live ELG / PART status --}}
                @if ($athlete && $criteria)
                    <div class="space-y-3">
                        <div>
                            <h3 class="text-sm font-semibold text-stone-900">Your eligibility and participation</h3>
                            <p class="mt-1 text-xs text-stone-500">
                                Computed live from your membership, profile and score data. This mirrors what ExCo sees on your athlete record.
                            </p>
                        </div>
                        <x-selection-criteria-cards :athlete="$athlete" :criteria="$criteria" />
                    </div>
                @endif
            </section>
        @endforeach
    </div>
</x-layouts.app>
