<x-layouts.app :title="'ExCo Decisions'">
    <div class="max-w-5xl space-y-6" x-data="{ selected: [] }">
        <x-page-header title="Decision Register" subtitle="Publish committee decisions for members. Votes are anonymous and do not immediately change a decision — they give ExCo an idea whether to relook at it.">
            <x-slot:actions>
                <a href="{{ route('exco.decisions.create') }}"
                   class="inline-flex items-center rounded-xl bg-emerald-700 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-800">
                    New decision
                </a>
                <a href="{{ route('decisions.index') }}"
                   class="inline-flex items-center rounded-xl bg-stone-100 px-4 py-2 text-sm font-semibold text-stone-700 hover:bg-stone-200"
                   target="_blank" rel="noopener">
                    View register
                </a>
            </x-slot:actions>
        </x-page-header>

        @if (session('success'))
            <div class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">
                {{ session('success') }}
            </div>
        @endif
        @if (session('error'))
            <div class="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">
                {{ session('error') }}
            </div>
        @endif

        <div class="rounded-2xl border border-stone-200 bg-white p-5">
            <h2 class="text-sm font-semibold text-stone-900">Reaction settings</h2>
            <p class="mt-1 text-xs text-stone-500">
                Feedback window length, and whether the split is always visible after 10 reactions or only after a member has reacted.
                Below 10 reactions the bar stays grey either way.
            </p>
            <form method="POST" action="{{ route('exco.decisions.visibility') }}" class="mt-4 flex flex-wrap items-end gap-3">
                @csrf
                <div>
                    <label for="decision_reaction_window_days" class="block text-xs font-semibold uppercase text-stone-500">Window (days)</label>
                    <input type="number" name="decision_reaction_window_days" id="decision_reaction_window_days"
                           min="1" max="90" required
                           value="{{ old('decision_reaction_window_days', $windowDays) }}"
                           class="mt-1 block w-28 rounded-lg border border-stone-300 px-3 py-2 text-sm">
                </div>
                <div>
                    <label for="decision_reaction_visibility" class="block text-xs font-semibold uppercase text-stone-500">Totals visibility</label>
                    <select name="decision_reaction_visibility" id="decision_reaction_visibility"
                            class="mt-1 block rounded-lg border border-stone-300 px-3 py-2 text-sm">
                        <option value="public" @selected($visibility === 'public')>Always visible after 10 reactions</option>
                        <option value="after_reaction" @selected($visibility === 'after_reaction')>Hidden until you have reacted</option>
                    </select>
                </div>
                <button type="submit" class="rounded-xl bg-stone-900 px-4 py-2 text-sm font-semibold text-white hover:bg-stone-800">
                    Save
                </button>
            </form>
        </div>

        {{-- Batch form is outside the table so row forms are not nested. Checkboxes use form="…". --}}
        <form id="decision-publish-batch"
              method="POST"
              action="{{ route('exco.decisions.publish-batch') }}"
              @submit="if (selected.length === 0) { $event.preventDefault(); alert('Select at least one draft.'); return false; } if (!confirm('Publish ' + selected.length + ' decision(s) and send one email to paid-up members?')) { $event.preventDefault(); }">
            @csrf
            <div class="mb-3 flex flex-wrap items-center gap-3">
                <button type="submit"
                        class="inline-flex items-center rounded-xl bg-emerald-700 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-800 disabled:opacity-40"
                        :disabled="selected.length === 0">
                    Publish selected
                </button>
                <p class="text-xs text-stone-500">
                    Tick draft decisions, then publish together — members get one digest email.
                </p>
            </div>
        </form>

        <x-admin-table>
            <table class="min-w-full divide-y divide-stone-200 text-sm">
                <thead class="bg-stone-50 text-xs uppercase tracking-wide text-stone-500">
                    <tr>
                        <th class="w-10 px-4 py-3 text-left font-semibold">
                            <span class="sr-only">Select</span>
                        </th>
                        <th class="px-4 py-3 text-left font-semibold">Decision</th>
                        <th class="px-4 py-3 text-left font-semibold">Status</th>
                        <th class="px-4 py-3 text-left font-semibold">Reactions</th>
                        <th class="px-4 py-3 text-right font-semibold">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-stone-100">
                    @forelse ($decisions as $decision)
                        @php
                            $likes = (int) ($decision->tally?->likes ?? 0);
                            $dislikes = (int) ($decision->tally?->dislikes ?? 0);
                            $neutrals = (int) ($decision->tally?->neutrals ?? 0);
                            $total = $likes + $dislikes + $neutrals;
                            $percentages = $decision->reactionPercentages();
                        @endphp
                        <tr>
                            <td class="px-4 py-3">
                                @if ($decision->isDraft())
                                    <input type="checkbox"
                                           form="decision-publish-batch"
                                           name="decision_ids[]"
                                           value="{{ $decision->id }}"
                                           class="rounded border-stone-300 text-emerald-700 focus:ring-emerald-600"
                                           x-model.number="selected">
                                @endif
                            </td>
                            <td class="px-4 py-3 text-stone-900">
                                <p class="font-medium">{{ $decision->title }}</p>
                                @if ($decision->reference)
                                    <p class="mt-0.5 text-xs text-stone-500">{{ $decision->reference }}</p>
                                @endif
                                @if ($decision->published_at)
                                    <p class="mt-0.5 text-xs text-stone-400">
                                        Published {{ $decision->published_at->timezone(config('app.timezone'))->format('d M Y H:i') }}
                                    </p>
                                @endif
                            </td>
                            <td class="px-4 py-3">
                                <span class="inline-flex rounded-full px-2 py-0.5 text-xs font-semibold {{ $decision->status->badgeClass() }}">
                                    {{ $decision->status->label() }}
                                </span>
                            </td>
                            <td class="px-4 py-3 text-stone-600">
                                @if ($total === 0)
                                    —
                                @elseif ($total < \App\Models\Decision::MIN_REACTIONS_FOR_RESULTS)
                                    {{ $total }} of {{ \App\Models\Decision::MIN_REACTIONS_FOR_RESULTS }}
                                @else
                                    {{ $total }} · {{ $percentages['for'] }}% 👍 · {{ $percentages['against'] }}% 👎 · {{ $percentages['neutral'] }}% 🤷
                                @endif
                            </td>
                            <td class="px-4 py-3 text-right">
                                <div class="inline-flex items-center justify-end gap-1">
                                    <a href="{{ route('exco.decisions.edit', $decision) }}"
                                       class="rounded-lg bg-stone-100 px-2 py-1 text-xs font-semibold text-stone-700 hover:bg-stone-200">
                                        Edit
                                    </a>
                                    @if ($decision->isDraft())
                                        <form method="POST" action="{{ route('exco.decisions.publish', $decision) }}" class="inline">
                                            @csrf
                                            <button type="submit" class="rounded-lg bg-emerald-50 px-2 py-1 text-xs font-semibold text-emerald-700 hover:bg-emerald-100">
                                                Publish alone
                                            </button>
                                        </form>
                                        <form method="POST" action="{{ route('exco.decisions.destroy', $decision) }}"
                                              class="inline"
                                              onsubmit="return confirm('Delete this draft?');">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="rounded-lg bg-red-50 px-2 py-1 text-xs font-semibold text-red-700 hover:bg-red-100">
                                                Delete
                                            </button>
                                        </form>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="px-4 py-8 text-center text-sm text-stone-500">
                                No decisions yet. Create drafts, then publish selected together.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </x-admin-table>

        {{ $decisions->links() }}
    </div>
</x-layouts.app>
