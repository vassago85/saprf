<x-layouts.app :title="'ExCo — Members'">
    <div class="max-w-4xl space-y-6">
        <div>
            <a href="{{ route('exco.meetings.index') }}" class="text-sm font-medium text-emerald-700 hover:text-emerald-800">← ExCo</a>
            <h1 class="mt-1 font-heading text-3xl font-bold text-stone-900">Committee members</h1>
            <p class="mt-1 text-sm text-stone-500">
                Everyone with the <strong>exco</strong> or <strong>chair</strong> role. Assign a position (Chair, Secretary,
                Treasurer, portfolio chair, etc.) so it renders alongside their name in printed minutes, on the meeting page,
                and next to action item owners. Leave blank for a general member without a specific portfolio.
            </p>
        </div>

        @if (session('success'))
            <div class="rounded-lg border border-emerald-300 bg-emerald-50 px-4 py-3 text-sm text-emerald-900">
                {{ session('success') }}
            </div>
        @endif
        @if (session('info'))
            <div class="rounded-lg border border-stone-300 bg-stone-50 px-4 py-3 text-sm text-stone-800">
                {{ session('info') }}
            </div>
        @endif
        @if (session('error'))
            <div class="rounded-lg border border-rose-300 bg-rose-50 px-4 py-3 text-sm text-rose-900">
                {{ session('error') }}
            </div>
        @endif

        {{-- Shared datalist. Free-text field, but every input on the
             page offers these as autocomplete suggestions so titles
             stay consistent. --}}
        <datalist id="exco-position-suggestions">
            @foreach ($suggestedPositions as $suggested)
                <option value="{{ $suggested }}"></option>
            @endforeach
        </datalist>

        <div class="overflow-hidden rounded-xl border border-stone-200 bg-white shadow-sm">
            <table class="min-w-full divide-y divide-stone-200 text-sm">
                <thead class="bg-stone-50 text-left text-xs font-semibold uppercase tracking-wide text-stone-500">
                    <tr>
                        <th class="px-4 py-3">Name</th>
                        <th class="px-4 py-3">Email</th>
                        <th class="px-4 py-3">Position</th>
                        <th class="px-4 py-3 text-right">Save</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-stone-200">
                    @forelse ($members as $member)
                        <tr>
                            <td class="whitespace-nowrap px-4 py-3 align-middle font-medium text-stone-900">
                                {{ $member->name }}
                                @if ($member->hasRole('chair'))
                                    <span class="ml-1 inline-flex rounded-full bg-emerald-100 px-2 py-0.5 text-[10px] font-semibold text-emerald-800 align-middle">Chair role</span>
                                @endif
                            </td>
                            <td class="whitespace-nowrap px-4 py-3 align-middle text-stone-600">
                                {{ $member->email }}
                            </td>
                            <td class="px-4 py-3 align-middle">
                                {{-- Each row is its own tiny form so a Save
                                     only submits that one member. The
                                     button is separated visually but part
                                     of the row's form via `form="..."`. --}}
                                <form method="POST" action="{{ route('exco.members.update', $member) }}" id="exco-member-form-{{ $member->id }}">
                                    @csrf
                                    @method('PUT')
                                    <input type="text" name="exco_position" list="exco-position-suggestions"
                                        value="{{ old('exco_position', $member->exco_position) }}"
                                        maxlength="100" autocomplete="off" placeholder="e.g. Secretary"
                                        class="w-full rounded-lg border border-stone-300 px-3 py-1.5 text-sm focus:border-emerald-500 focus:ring-emerald-500" />
                                </form>
                            </td>
                            <td class="whitespace-nowrap px-4 py-3 align-middle text-right">
                                <button type="submit" form="exco-member-form-{{ $member->id }}"
                                    class="rounded-lg bg-emerald-700 px-3 py-1.5 text-xs font-semibold text-white hover:bg-emerald-800">
                                    Save
                                </button>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="px-4 py-6 text-center text-sm text-stone-500">
                                No users with the exco or chair role yet. Assign the role from Users admin, then come back here.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="rounded-xl border border-stone-200 bg-stone-50 p-4 text-xs text-stone-600">
            <p class="font-semibold text-stone-800">Suggested positions</p>
            <p class="mt-1">
                {{ implode(', ', $suggestedPositions) }}.
                The list is a hint only — type anything up to 100 characters if the portfolio you need isn't listed.
            </p>
        </div>
    </div>
</x-layouts.app>
