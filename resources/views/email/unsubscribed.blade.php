<x-layouts.guest>
    <x-slot:title>Unsubscribed - SAPRF</x-slot:title>

    <div class="mx-auto max-w-lg px-6 py-16">
        <div class="rounded-2xl border border-stone-200 bg-white p-8 shadow-sm">
            <div class="flex items-center gap-3">
                <div class="rounded-full bg-emerald-100 p-2">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="size-5 text-emerald-700">
                        <path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5"/>
                    </svg>
                </div>
                <h1 class="font-heading text-2xl font-bold text-stone-900">You've been unsubscribed</h1>
            </div>

            <p class="mt-4 text-sm text-stone-600">
                Hi {{ $user->name }} — we've turned off SAPRF emails for the following
                {{ $mode === 'all_non_mandatory' ? 'categories' : 'category' }}:
            </p>

            <ul class="mt-3 space-y-1 text-sm text-stone-800">
                @foreach ($muted as $value)
                    @php $c = \App\Enums\AnnouncementCategory::tryFrom($value); @endphp
                    @if ($c)
                        <li class="flex items-center gap-2">
                            <span class="size-1.5 rounded-full bg-stone-400"></span>
                            {{ $c->label() }}
                        </li>
                    @endif
                @endforeach
            </ul>

            @if ($mode === 'all_non_mandatory')
                <div class="mt-6 rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">
                    <p class="font-semibold">Policy changes and urgent notices still send.</p>
                    <p class="mt-1 text-amber-800">
                        Those categories carry compliance weight — Exco needs a delivery record
                        for every SAPRF member. If you don't want any SAPRF email at all,
                        please contact the federation.
                    </p>
                </div>
            @endif

            <div class="mt-8 flex flex-col gap-3 sm:flex-row">
                <a href="{{ url('/') }}"
                    class="rounded-xl bg-stone-100 px-4 py-2 text-center text-sm font-semibold text-stone-700 hover:bg-stone-200">
                    Back to SAPRF
                </a>
                <a href="{{ route('profile') }}"
                    class="rounded-xl bg-emerald-700 px-4 py-2 text-center text-sm font-semibold text-white hover:bg-emerald-800">
                    Fine-tune preferences (login required)
                </a>
            </div>
        </div>
    </div>
</x-layouts.guest>
