<x-layouts.app :title="'Score Details'">
    <div class="flex items-center justify-between">
        <h1 class="font-heading text-3xl font-bold text-stone-900">Score Details</h1>
        <flux:button href="{{ route('scores.index') }}" variant="ghost" icon="arrow-left">Back</flux:button>
    </div>

    <div class="mt-6 border-t border-stone-200"></div>

    <div class="mt-6 max-w-3xl space-y-6">
        <div class="rounded-xl border border-stone-200 bg-white shadow-sm p-6">
            <h2 class="text-lg font-semibold text-stone-900 mb-4">Score Information</h2>

            <dl class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                <div>
                    <dt class="text-[11px] font-semibold uppercase tracking-wider text-stone-400">Match</dt>
                    <dd class="mt-1.5 text-sm">
                        <a href="{{ route('matches.show', $score->match) }}" class="text-emerald-700 hover:text-emerald-900 hover:underline">{{ $score->match->name }}</a>
                    </dd>
                </div>
                <div>
                    <dt class="text-[11px] font-semibold uppercase tracking-wider text-stone-400">Shooter</dt>
                    <dd class="mt-1.5 text-sm text-stone-900">{{ $score->user->name ?? $score->shooter_name ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-[11px] font-semibold uppercase tracking-wider text-stone-400">Impacts</dt>
                    <dd class="mt-1.5 text-lg font-bold text-stone-900 font-mono">{{ $score->raw_score }}</dd>
                </div>
                <div>
                    <dt class="text-[11px] font-semibold uppercase tracking-wider text-stone-400">Placement</dt>
                    <dd class="mt-1.5 text-sm text-stone-900">{{ $score->placement ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-[11px] font-semibold uppercase tracking-wider text-stone-400">Division</dt>
                    <dd class="mt-1.5 text-sm text-stone-900">{{ $score->division?->name ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-[11px] font-semibold uppercase tracking-wider text-stone-400">Status</dt>
                    <dd class="mt-1.5">
                        @switch($score->status)
                            @case('valid')
                                <span class="inline-flex items-center rounded-full bg-emerald-50 px-2.5 py-0.5 text-xs font-semibold text-emerald-700 ring-1 ring-inset ring-emerald-600/20">Valid</span>
                                @break
                            @case('pending')
                                <span class="inline-flex items-center rounded-full bg-amber-50 px-2.5 py-0.5 text-xs font-semibold text-amber-700 ring-1 ring-inset ring-amber-600/20">Pending</span>
                                @break
                            @case('overridden')
                                <span class="inline-flex items-center rounded-full bg-sky-50 px-2.5 py-0.5 text-xs font-semibold text-sky-700 ring-1 ring-inset ring-sky-600/20">Overridden</span>
                                @break
                            @case('invalid')
                                <span class="inline-flex items-center rounded-full bg-red-50 px-2.5 py-0.5 text-xs font-semibold text-red-700 ring-1 ring-inset ring-red-600/20">Invalid</span>
                                @break
                        @endswitch
                    </dd>
                </div>
                @if ($score->validation_reason)
                    <div class="sm:col-span-2">
                        <dt class="text-[11px] font-semibold uppercase tracking-wider text-stone-400">Validation Reason</dt>
                        <dd class="mt-1.5 text-sm text-stone-700">{{ $score->validation_reason }}</dd>
                    </div>
                @endif
            </dl>
        </div>

        @role('owner|admin')
            <div class="rounded-xl border border-stone-200 bg-white shadow-sm p-6">
                <h2 class="text-lg font-semibold text-stone-900 mb-4">Override Score</h2>

                <form method="POST" action="{{ route('scores.override', $score) }}" class="space-y-4">
                    @csrf

                    <div>
                        <label for="status" class="block text-sm font-medium text-stone-700 mb-1">New Status <span class="text-red-500">*</span></label>
                        <select name="status" id="status" required class="block w-full rounded-lg border border-stone-300 text-sm focus:ring-emerald-500 focus:border-emerald-500">
                            <option value="">Select status…</option>
                            <option value="valid">Valid</option>
                            <option value="invalid">Invalid</option>
                            <option value="overridden">Overridden</option>
                        </select>
                    </div>

                    <div>
                        <label for="reason" class="block text-sm font-medium text-stone-700 mb-1">Reason <span class="text-red-500">*</span></label>
                        <textarea name="reason" id="reason" rows="3" placeholder="Explain the reason for this override…" required class="block w-full rounded-lg border border-stone-300 text-sm focus:ring-emerald-500 focus:border-emerald-500"></textarea>
                    </div>

                    @if ($errors->any())
                        <div class="rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-700">
                            <ul class="list-inside list-disc space-y-1">
                                @foreach ($errors->all() as $error)
                                    <li>{{ $error }}</li>
                                @endforeach
                            </ul>
                        </div>
                    @endif

                    <flux:button type="submit" variant="danger">Apply Override</flux:button>
                </form>
            </div>
        @endrole
    </div>
</x-layouts.app>
