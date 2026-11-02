<x-layouts.app :title="$match->name . ' — Settings'">
    @include('matches.workspace._chrome')

    {{-- Two-column layout on >= lg: form on the left, sticky section nav
         + Save/Cancel on the right. On < lg the nav collapses into a
         horizontal strip above the form plus a sticky Save bar at the
         bottom (both inside `_section-nav`). --}}
    <div class="mt-6 grid gap-6 lg:grid-cols-[minmax(0,1fr)_18rem]">
        <form id="match-settings-form"
              method="POST"
              action="{{ route('matches.update', $match) }}"
              enctype="multipart/form-data"
              class="space-y-4">
            @csrf
            @method('PUT')

            {{-- Seven sections, in the order dictated by the brief:
                 1. Basics
                 2. When & where
                 3. Entries
                 4. Fees
                 5. Director & contact
                 6. Public page
                 7. Scoring

                 Each section partial shares the same markup used by the
                 Create page. The per-field "Locked" badges come from
                 `$lockedByPaidEntries` — the page-wide locked banner has
                 been retired in favour of these inline chips with the
                 same explanation surfaced as a tooltip. --}}
            @include('matches._form._basics')
            @include('matches._form._when-where')
            @include('matches._form._entries')
            @include('matches._form._fees')
            @include('matches._form._director-contact')
            @include('matches._form._public-page')
            @include('matches._form._scoring')

            @if ($errors->any())
                <div class="rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-700">
                    <ul class="list-inside list-disc space-y-1">
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif
        </form>

        @include('matches._form._section-nav')
    </div>
</x-layouts.app>
