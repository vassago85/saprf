<x-layouts.app :title="$athlete->user?->name.' · '.$cycle->series.' '.$cycle->season">
    {{-- Model references use fully-qualified names because a `use` block
         inside an inline PHP directive would be compiled into the component
         slot closure, which PHP does not permit. --}}
    @php
        $statusBadge = fn (string $status): string => match ($status) {
            \App\Services\Selection\SelectionCriteriaStatus::STATUS_MET => 'bg-emerald-100 text-emerald-800',
            \App\Services\Selection\SelectionCriteriaStatus::STATUS_NOT_MET => 'bg-red-100 text-red-800',
            default => 'bg-amber-100 text-amber-800',
        };
        $statusLabel = fn (string $status): string => match ($status) {
            \App\Services\Selection\SelectionCriteriaStatus::STATUS_MET => 'Met',
            \App\Services\Selection\SelectionCriteriaStatus::STATUS_NOT_MET => 'Not met',
            default => 'Needs review',
        };
        $snap = $athlete->participationSnapshot;
    @endphp
    <div class="space-y-6">
        <div class="flex items-start justify-between gap-4">
            <div>
                <div class="text-xs font-semibold uppercase tracking-widest text-stone-400">
                    <a href="{{ route('selection.cycles.athletes.index', $cycle) }}" class="hover:text-stone-600">← All athletes</a>
                    · {{ $cycle->series }} {{ $cycle->season }}
                </div>
                <h1 class="font-heading text-3xl font-bold text-stone-900 tracking-tight">{{ $athlete->user?->name }}</h1>
                <p class="text-sm text-stone-500">{{ $athlete->user?->email }} · Division: {{ $athlete->claimedDivision?->name ?? 'not set' }} · Club: {{ $athlete->user?->club?->name ?? '—' }}</p>
            </div>
            <div class="flex items-center gap-2">
                <span class="rounded-full bg-stone-800 px-3 py-1 text-xs font-semibold text-white">{{ str_replace('_', ' ', $athlete->state) }}</span>
                @can('reevaluate', $athlete)
                    <form method="POST" action="{{ route('selection.cycles.athletes.reevaluate', [$cycle, $athlete]) }}">
                        @csrf
                        <button type="submit" class="rounded-lg bg-emerald-700 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-800">Re-evaluate</button>
                    </form>
                @endcan
            </div>
        </div>

        @if (session('success'))
            <div class="rounded-lg border border-emerald-200 bg-emerald-50 p-3 text-sm text-emerald-800">{{ session('success') }}</div>
        @endif

        @if ($cycle->isAssumeQualified())
            <div class="rounded-lg border border-sky-200 bg-sky-50 p-3 text-sm text-sky-800">
                This cycle auto-qualifies everyone for progression — the criteria status below is <strong>informational</strong> (computed live from real results and membership data) and does not block the athlete.
            </div>
        @endif

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
            <div class="rounded-xl border border-stone-200 bg-white p-5 shadow-sm">
                <div class="flex items-center justify-between mb-1">
                    <h2 class="text-sm font-semibold text-stone-700">Eligibility (ELG)</h2>
                    <span class="text-xs font-semibold text-stone-500">{{ $criteria['eligibility_met'] }} / {{ $criteria['eligibility_total'] }} met</span>
                </div>
                @php($elgPct = $criteria['eligibility_total'] > 0 ? (int) round($criteria['eligibility_met'] / $criteria['eligibility_total'] * 100) : 0)
                <div class="mb-4 h-2 w-full overflow-hidden rounded-full bg-stone-100">
                    <div class="h-full rounded-full bg-emerald-500 transition-all" style="width: {{ $elgPct }}%"></div>
                </div>
                <ul class="divide-y divide-stone-100">
                    @foreach ($criteria['eligibility'] as $rule)
                        <li class="py-2">
                            <div class="flex items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <span class="font-mono text-xs text-stone-400">{{ $rule['code'] }}</span>
                                    <span class="text-sm text-stone-700">· {{ $rule['name'] }}</span>
                                </div>
                                <span class="shrink-0 rounded-full px-2.5 py-0.5 text-xs font-semibold {{ $statusBadge($rule['status']) }}">{{ $statusLabel($rule['status']) }}</span>
                            </div>
                            @if (! empty($rule['detail']))
                                <details class="mt-1">
                                    <summary class="cursor-pointer text-xs text-stone-400 hover:text-stone-600">details</summary>
                                    <pre class="mt-1 whitespace-pre-wrap font-mono text-xs text-stone-500">{{ json_encode($rule['detail'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                                </details>
                            @endif
                        </li>
                    @endforeach
                </ul>
            </div>

            <div class="rounded-xl border border-stone-200 bg-white p-5 shadow-sm">
                <div class="flex items-center justify-between mb-1">
                    <h2 class="text-sm font-semibold text-stone-700">Participation (PART)</h2>
                    <span class="text-xs font-semibold text-stone-500">{{ $criteria['participation_met'] }} / {{ $criteria['participation_total'] }} met</span>
                </div>
                @php($partPct = $criteria['participation_total'] > 0 ? (int) round($criteria['participation_met'] / $criteria['participation_total'] * 100) : 0)
                <div class="mb-4 h-2 w-full overflow-hidden rounded-full bg-stone-100">
                    <div class="h-full rounded-full bg-emerald-500 transition-all" style="width: {{ $partPct }}%"></div>
                </div>
                @if ($snap)
                    <div class="mb-4 grid grid-cols-3 gap-3 text-center">
                        <div><div class="text-2xl font-bold">{{ $snap->provincial_1d_count }}<span class="text-sm font-normal text-stone-400"> / 3</span></div><div class="text-xs text-stone-500">Prov. 1-day</div></div>
                        <div><div class="text-2xl font-bold">{{ $snap->national_2d_count + $snap->international_2d_count }}<span class="text-sm font-normal text-stone-400"> / 2</span></div><div class="text-xs text-stone-500">2-day (nat+int'l)</div></div>
                        <div><div class="text-2xl font-bold">{{ $snap->out_of_home_province_2d_count }}<span class="text-sm font-normal text-stone-400"> / 1</span></div><div class="text-xs text-stone-500">Out-of-home 2-day</div></div>
                        <div><div class="text-2xl font-bold">{{ $snap->sa_champs_shot ? 'Yes' : 'No' }}</div><div class="text-xs text-stone-500">SA Champs</div></div>
                        <div class="col-span-2"><div class="text-xs text-stone-500">Computed {{ $snap->computed_at?->format('Y-m-d H:i') }}</div></div>
                    </div>
                @else
                    <p class="text-sm text-stone-500 mb-3">No snapshot yet — re-evaluate to compute.</p>
                @endif
                <ul class="divide-y divide-stone-100">
                    @foreach ($criteria['participation'] as $rule)
                        @php($waived = $athlete->waivers->firstWhere(fn ($w) => $w->waived_rule_id === $rule['code'] && $w->outcome === \App\Models\SelectionWaiver::OUTCOME_GRANTED))
                        <li class="py-2 flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <span class="font-mono text-xs text-stone-400">{{ $rule['code'] }}</span>
                                <span class="text-sm text-stone-700">· {{ $rule['name'] }}</span>
                            </div>
                            <div class="flex shrink-0 items-center gap-1.5">
                                @if (! $rule['boolean'] && $rule['required'] !== null)
                                    <span class="text-xs font-medium text-stone-500">{{ $rule['current'] }} / {{ $rule['required'] }}</span>
                                @endif
                                <span class="rounded-full px-2.5 py-0.5 text-xs font-semibold {{ $statusBadge($rule['status']) }}">{{ $statusLabel($rule['status']) }}</span>
                                @if ($waived) <span class="rounded-full bg-sky-100 px-2 py-0.5 text-xs font-semibold text-sky-800">waived</span> @endif
                            </div>
                        </li>
                    @endforeach
                </ul>
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
            <div class="rounded-xl border border-stone-200 bg-white p-5 shadow-sm">
                <h2 class="text-sm font-semibold text-stone-700 mb-3">Athlete metadata</h2>
                <form method="POST" action="{{ route('selection.cycles.athletes.update', [$cycle, $athlete]) }}" class="space-y-3">
                    @csrf @method('PUT')
                    <div>
                        <label class="block text-sm font-medium text-stone-700 mb-1">Claimed division</label>
                        <select name="claimed_division_id" class="block w-full rounded-lg border border-stone-300 text-sm">
                            <option value="">(unset)</option>
                            @foreach ($divisions as $d)
                                <option value="{{ $d->id }}" @selected($athlete->claimed_division_id === $d->id)>{{ $d->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-stone-700 mb-1">Manual eligibility notes</label>
                        <textarea name="manual_eligibility_notes" rows="3" class="block w-full rounded-lg border border-stone-300 text-sm">{{ old('manual_eligibility_notes', $athlete->manual_eligibility_notes) }}</textarea>
                    </div>
                    <button type="submit" class="rounded-lg bg-emerald-700 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-800">Save</button>
                </form>
            </div>

            <div class="rounded-xl border border-stone-200 bg-white p-5 shadow-sm">
                <h2 class="text-sm font-semibold text-stone-700 mb-3">Declaration (DEC-01)</h2>
                @php
                    // Pull the actual form-rule id from the cycle policy JSON so
                    // we display ELG-05 (PR22) / ELG-06 (PRS) verbatim from the
                    // governing document, instead of the hard-coded ELG-07 that
                    // was never in any policy.
                    $policyElg = collect($cycle->activePolicy?->spec_json['eligibility']['rules'] ?? [])
                        ->firstWhere('check', 'declaration_form_received');
                    $formRuleId = $policyElg['id'] ?? ($cycle->series === 'PR22' ? 'ELG-05' : 'ELG-06');
                    $formData = $athlete->declaration?->form_data ?? [];
                    $attestations = $formData['attestations'] ?? null;
                    $receivedChannel = $formData['received_channel'] ?? null;
                @endphp
                @if ($athlete->declaration)
                    <p class="text-sm text-stone-700">
                        Status: <span class="font-semibold">{{ $athlete->declaration->status }}</span><br>
                        Submitted: {{ $athlete->declaration->submitted_at?->format('Y-m-d H:i') ?? '—' }}<br>
                        Captured by: {{ optional($athlete->declaration->capturedBy)->name ?? '—' }}<br>
                        {{ $formRuleId }} form received: {{ ($formData['eligibility_to_compete_received'] ?? false) ? 'Yes' : 'No' }}
                        @if ($receivedChannel === 'online_form')
                            <span class="ml-1 rounded-full bg-emerald-100 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wider text-emerald-800">via online form</span>
                        @endif
                    </p>
                    @if ($attestations)
                        <div class="mt-3 rounded-lg border border-stone-200 bg-stone-50/60 p-3 text-xs text-stone-700 space-y-1">
                            <p class="font-semibold text-stone-600">Attestations submitted</p>
                            <ul class="space-y-0.5">
                                <li>{{ ($attestations['intention_to_participate'] ?? false) ? '✓' : '✗' }} Intention to participate</li>
                                <li>{{ ($attestations['able_and_willing'] ?? false) ? '✓' : '✗' }} Able and willing to undertake the programme</li>
                                <li>{{ ($attestations['satisfy_preconditions'] ?? false) ? '✓' : '✗' }} Will satisfy ExCo preconditions</li>
                                <li>{{ ($attestations['no_impairment'] ?? false) ? '✓' : '✗' }} No impairment</li>
                            </ul>
                            @if (! empty($formData['signature']))
                                <p class="mt-2 text-stone-500">Signature: <span class="font-mono">{{ $formData['signature'] }}</span></p>
                            @endif
                            @if (! empty($formData['notes']))
                                <p class="mt-1 text-stone-500">Notes: {{ $formData['notes'] }}</p>
                            @endif
                        </div>
                    @endif
                    @if ($athlete->declaration->signed_form_path)
                        <p class="mt-2 text-xs text-stone-500">Signed PDF on file: <span class="font-mono">{{ basename($athlete->declaration->signed_form_path) }}</span></p>
                    @endif
                @else
                    <p class="text-sm text-stone-500">Not yet on file.</p>
                @endif
                <details class="mt-4">
                    <summary class="cursor-pointer text-sm font-medium text-emerald-700">Capture / update declaration (paper submission)</summary>
                    <form method="POST" action="{{ route('selection.cycles.athletes.declaration.store', [$cycle, $athlete]) }}" enctype="multipart/form-data" class="mt-3 space-y-3">
                        @csrf
                        <div>
                            <label class="block text-sm font-medium text-stone-700 mb-1">Submitted at <span class="text-red-500">*</span></label>
                            <input type="datetime-local" name="submitted_at" required value="{{ optional($athlete->declaration?->submitted_at)->format('Y-m-d\TH:i') }}" class="block w-full rounded-lg border border-stone-300 text-sm">
                        </div>
                        <label class="flex items-center gap-2 text-sm text-stone-700">
                            <input type="hidden" name="eligibility_to_compete_received" value="0">
                            <input type="checkbox" name="eligibility_to_compete_received" value="1" @checked($athlete->declaration?->form_data['eligibility_to_compete_received'] ?? false)>
                            {{ $formRuleId }} "Eligibility to Compete" form received
                        </label>
                        <div>
                            <label class="block text-sm font-medium text-stone-700 mb-1">Notes</label>
                            <textarea name="notes" rows="2" class="block w-full rounded-lg border border-stone-300 text-sm">{{ $athlete->declaration?->form_data['notes'] ?? '' }}</textarea>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-stone-700 mb-1">Signed PDF (optional)</label>
                            <input type="file" name="signed_form" accept="application/pdf" class="block w-full text-sm">
                        </div>
                        <button type="submit" class="rounded-lg bg-emerald-700 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-800">Save declaration</button>
                    </form>
                </details>
            </div>
        </div>

        <div class="rounded-xl border border-stone-200 bg-white p-5 shadow-sm">
            <h2 class="text-sm font-semibold text-stone-700 mb-3">Waivers</h2>
            <table class="min-w-full text-sm">
                <thead class="bg-stone-50 text-xs uppercase text-stone-500">
                    <tr><th class="px-3 py-2 text-left">Rule</th><th class="px-3 py-2 text-left">Request</th><th class="px-3 py-2 text-left">Outcome</th><th class="px-3 py-2 text-left">Decided</th><th class="px-3 py-2 text-right">Actions</th></tr>
                </thead>
                <tbody class="divide-y divide-stone-100">
                    @forelse ($athlete->waivers as $w)
                        <tr>
                            <td class="px-3 py-2 font-mono text-xs">{{ $w->waived_rule_id }}</td>
                            <td class="px-3 py-2 text-xs text-stone-600">{{ Str::limit($w->request_text, 100) }}</td>
                            <td class="px-3 py-2"><span class="rounded-full px-2 py-0.5 text-xs font-semibold {{ ['granted' => 'bg-emerald-100 text-emerald-800', 'denied' => 'bg-red-100 text-red-800', 'pending' => 'bg-amber-100 text-amber-800'][$w->outcome] ?? 'bg-stone-100 text-stone-600' }}">{{ $w->outcome }}</span></td>
                            <td class="px-3 py-2 text-xs text-stone-500">{{ $w->decided_at?->format('Y-m-d') ?? '—' }} · {{ optional($w->decidedBy)->name }}</td>
                            <td class="px-3 py-2 text-right">
                                @can('decide', $w)
                                    @if ($w->outcome === \App\Models\SelectionWaiver::OUTCOME_PENDING)
                                        <form method="POST" action="{{ route('selection.cycles.athletes.waivers.decide', [$cycle, $athlete, $w]) }}" class="inline-flex items-center gap-1">
                                            @csrf @method('PUT')
                                            <input type="text" name="response_text" placeholder="Response…" class="rounded border border-stone-300 px-2 py-1 text-xs">
                                            <button name="outcome" value="granted" class="rounded bg-emerald-600 px-2 py-1 text-xs font-semibold text-white">Grant</button>
                                            <button name="outcome" value="denied" class="rounded bg-red-600 px-2 py-1 text-xs font-semibold text-white">Deny</button>
                                        </form>
                                    @else
                                        <span class="text-xs text-stone-400">{{ Str::limit($w->response_text, 40) }}</span>
                                    @endif
                                @endcan
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="px-3 py-6 text-center text-stone-400">No waiver requests.</td></tr>
                    @endforelse
                </tbody>
            </table>
            @can('create', App\Models\SelectionWaiver::class)
                <details class="mt-4">
                    <summary class="cursor-pointer text-sm font-medium text-emerald-700">Record a waiver request</summary>
                    <form method="POST" action="{{ route('selection.cycles.athletes.waivers.store', [$cycle, $athlete]) }}" enctype="multipart/form-data" class="mt-3 grid grid-cols-1 md:grid-cols-3 gap-3">
                        @csrf
                        <select name="waived_rule_id" required class="rounded-lg border border-stone-300 text-sm">
                            @foreach (['PART-01','PART-02','PART-03','PART-04','PART-05'] as $r) <option value="{{ $r }}">{{ $r }}</option> @endforeach
                        </select>
                        <input type="text" name="request_text" placeholder="Reason…" class="rounded-lg border border-stone-300 text-sm md:col-span-2">
                        <input type="file" name="request_file" class="text-sm md:col-span-2">
                        <button type="submit" class="rounded-lg bg-emerald-700 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-800">Record request</button>
                    </form>
                </details>
            @endcan
        </div>

        <div class="rounded-xl border border-stone-200 bg-white p-5 shadow-sm">
            <h2 class="text-sm font-semibold text-stone-700 mb-3">Appeals</h2>
            <table class="min-w-full text-sm">
                <thead class="bg-stone-50 text-xs uppercase text-stone-500">
                    <tr><th class="px-3 py-2 text-left">Lodged</th><th class="px-3 py-2 text-left">Reason</th><th class="px-3 py-2 text-left">Fee</th><th class="px-3 py-2 text-left">Outcome</th><th class="px-3 py-2 text-right">Actions</th></tr>
                </thead>
                <tbody class="divide-y divide-stone-100">
                    @forelse ($athlete->appeals as $ap)
                        <tr>
                            <td class="px-3 py-2 text-xs">{{ $ap->lodged_at?->format('Y-m-d') }}</td>
                            <td class="px-3 py-2 text-xs text-stone-600">{{ Str::limit($ap->reason, 100) }}</td>
                            <td class="px-3 py-2 text-xs">R{{ number_format((float) $ap->fee_amount, 2) }} @if ($ap->fee_paid_at)<span class="text-emerald-700">paid</span>@else<span class="text-amber-700">unpaid</span>@endif</td>
                            <td class="px-3 py-2"><span class="rounded-full px-2 py-0.5 text-xs font-semibold {{ ['upheld' => 'bg-emerald-100 text-emerald-800', 'dismissed' => 'bg-red-100 text-red-800', 'withdrawn' => 'bg-stone-100 text-stone-600', 'pending' => 'bg-amber-100 text-amber-800'][$ap->outcome] ?? 'bg-stone-100 text-stone-600' }}">{{ $ap->outcome }}</span></td>
                            <td class="px-3 py-2 text-right">
                                @can('decide', $ap)
                                    @if ($ap->outcome === \App\Models\SelectionAppeal::OUTCOME_PENDING)
                                        <form method="POST" action="{{ route('selection.cycles.athletes.appeals.decide', [$cycle, $athlete, $ap]) }}" class="inline-flex items-center gap-1">
                                            @csrf @method('PUT')
                                            <button name="outcome" value="upheld" class="rounded bg-emerald-600 px-2 py-1 text-xs font-semibold text-white">Uphold</button>
                                            <button name="outcome" value="dismissed" class="rounded bg-red-600 px-2 py-1 text-xs font-semibold text-white">Dismiss</button>
                                            <button name="outcome" value="withdrawn" class="rounded bg-stone-500 px-2 py-1 text-xs font-semibold text-white">Withdraw</button>
                                        </form>
                                    @endif
                                @endcan
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="px-3 py-6 text-center text-stone-400">No appeals lodged.</td></tr>
                    @endforelse
                </tbody>
            </table>
            @can('create', App\Models\SelectionAppeal::class)
                <details class="mt-4">
                    <summary class="cursor-pointer text-sm font-medium text-emerald-700">Record an appeal</summary>
                    <form method="POST" action="{{ route('selection.cycles.athletes.appeals.store', [$cycle, $athlete]) }}" class="mt-3 grid grid-cols-1 md:grid-cols-3 gap-3">
                        @csrf
                        <input type="date" name="lodged_at" required class="rounded-lg border border-stone-300 text-sm">
                        <input type="date" name="fee_paid_at" class="rounded-lg border border-stone-300 text-sm" placeholder="Fee paid at">
                        <input type="number" name="fee_amount" step="0.01" min="0" value="5000.00" class="rounded-lg border border-stone-300 text-sm">
                        <textarea name="reason" rows="2" required placeholder="Reason for appeal…" class="rounded-lg border border-stone-300 text-sm md:col-span-3"></textarea>
                        <button type="submit" class="rounded-lg bg-emerald-700 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-800 md:col-span-3">Record appeal</button>
                    </form>
                </details>
            @endcan
        </div>
    </div>
</x-layouts.app>
