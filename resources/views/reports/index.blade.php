<x-layouts.app :title="'Reports - SAPRF'">
    <div class="space-y-6">
        <div>
            <h1 class="font-heading text-3xl font-bold text-stone-900 tracking-tight">Reports</h1>
            <p class="mt-1 text-sm text-stone-500">Federation reports for governance, selection, and reporting to stakeholders.</p>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
            {{-- Sponsorship --}}
            <a href="{{ route('reports.sponsorship') }}" class="group rounded-2xl border border-stone-200 bg-white p-6 shadow-sm hover:shadow-md hover:border-amber-300 transition">
                <div class="flex items-start justify-between mb-3">
                    <div class="rounded-xl bg-amber-50 p-2.5 ring-1 ring-amber-100">
                        <svg class="h-5 w-5 text-amber-700" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9 12h3.75M9 15h3.75M9 18h3.75m3 .75H18a2.25 2.25 0 0 0 2.25-2.25V6.108c0-1.135-.845-2.098-1.976-2.192a48.424 48.424 0 0 0-1.123-.08m-5.801 0c-.065.21-.1.433-.1.664 0 .414.336.75.75.75h4.5a.75.75 0 0 0 .75-.75 2.25 2.25 0 0 0-.1-.664m-5.8 0A2.251 2.251 0 0 1 13.5 2.25H15c1.012 0 1.867.668 2.15 1.586m-5.8 0c-.376.023-.75.05-1.124.08C9.095 4.01 8.25 4.973 8.25 6.108V8.25m0 0H4.875c-.621 0-1.125.504-1.125 1.125v11.25c0 .621.504 1.125 1.125 1.125h9.75c.621 0 1.125-.504 1.125-1.125V9.375c0-.621-.504-1.125-1.125-1.125H8.25ZM6.75 12h.008v.008H6.75V12Zm0 3h.008v.008H6.75V15Zm0 3h.008v.008H6.75V18Z"/>
                        </svg>
                    </div>
                    <svg class="h-4 w-4 text-stone-300 group-hover:text-amber-500 group-hover:translate-x-0.5 transition" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5"/></svg>
                </div>
                <h3 class="font-semibold text-stone-900">Sponsorship Report</h3>
                <p class="mt-1 text-sm text-stone-500">Sponsor profiles, payment history, total revenue per sponsor and tier.</p>
            </a>

            {{-- Selection --}}
            <a href="{{ route('reports.selection') }}" class="group rounded-2xl border border-stone-200 bg-white p-6 shadow-sm hover:shadow-md hover:border-emerald-300 transition">
                <div class="flex items-start justify-between mb-3">
                    <div class="rounded-xl bg-emerald-50 p-2.5 ring-1 ring-emerald-100">
                        <svg class="h-5 w-5 text-emerald-700" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 18.75h-9m9 0a3 3 0 0 1 3 3h-15a3 3 0 0 1 3-3m9 0v-3.375c0-.621-.503-1.125-1.125-1.125h-.871M7.5 18.75v-3.375c0-.621.504-1.125 1.125-1.125h.872m5.007 0H9.497m5.007 0a7.454 7.454 0 0 1-.982-3.172M9.497 14.25a7.454 7.454 0 0 0 .981-3.172M5.25 4.236c-.982.143-1.954.317-2.916.52A6.003 6.003 0 0 0 7.73 9.728M5.25 4.236V4.5c0 2.108.966 3.99 2.48 5.228M5.25 4.236V2.721C7.456 2.41 9.71 2.25 12 2.25c2.291 0 4.545.16 6.75.47v1.516M7.73 9.728a6.726 6.726 0 0 0 2.748 1.35m8.272-6.842V4.5c0 2.108-.966 3.99-2.48 5.228m2.48-5.492a46.32 46.32 0 0 1 2.916.52 6.003 6.003 0 0 1-5.395 4.972m0 0a6.726 6.726 0 0 1-2.749 1.35m0 0a6.772 6.772 0 0 1-3.044 0"/>
                        </svg>
                    </div>
                    <svg class="h-4 w-4 text-stone-300 group-hover:text-emerald-500 group-hover:translate-x-0.5 transition" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5"/></svg>
                </div>
                <h3 class="font-semibold text-stone-900">Selection Report</h3>
                <p class="mt-1 text-sm text-stone-500">Qualified shooters per series and season, with rank, points, and out-of-province status.</p>
            </a>

            {{-- Participation --}}
            <a href="{{ route('reports.participation') }}" class="group rounded-2xl border border-stone-200 bg-white p-6 shadow-sm hover:shadow-md hover:border-sky-300 transition">
                <div class="flex items-start justify-between mb-3">
                    <div class="rounded-xl bg-sky-50 p-2.5 ring-1 ring-sky-100">
                        <svg class="h-5 w-5 text-sky-700" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 0 1 3 19.875v-6.75ZM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 0 1-1.125-1.125V8.625ZM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 0 1-1.125-1.125V4.125Z"/>
                        </svg>
                    </div>
                    <svg class="h-4 w-4 text-stone-300 group-hover:text-sky-500 group-hover:translate-x-0.5 transition" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5"/></svg>
                </div>
                <h3 class="font-semibold text-stone-900">Participation Report</h3>
                <p class="mt-1 text-sm text-stone-500">Match-by-match entries, scores, and unique shooters across the season. Province breakdown.</p>
            </a>

            {{-- Financial Dashboard (link out) --}}
            <a href="{{ route('financials.dashboard') }}" class="group rounded-2xl border border-stone-200 bg-white p-6 shadow-sm hover:shadow-md hover:border-stone-300 transition">
                <div class="flex items-start justify-between mb-3">
                    <div class="rounded-xl bg-stone-100 p-2.5 ring-1 ring-stone-200">
                        <svg class="h-5 w-5 text-stone-700" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v12m-3-2.818.879.659c1.171.879 3.07.879 4.242 0 1.172-.879 1.172-2.303 0-3.182C13.536 12.219 12.768 12 12 12c-2.21 0-4-1.79-4-4s1.79-4 4-4c.768 0 1.536.219 2.121.659C15.293 5.518 16.243 7 17.5 7"/>
                        </svg>
                    </div>
                    <svg class="h-4 w-4 text-stone-300 group-hover:text-stone-500 group-hover:translate-x-0.5 transition" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5"/></svg>
                </div>
                <h3 class="font-semibold text-stone-900">Financial Reports</h3>
                <p class="mt-1 text-sm text-stone-500">Revenue, expenses, payouts, and transaction history.</p>
            </a>

            {{-- SASCOC --}}
            @role('owner')
            <a href="{{ route('sascoc-report.index') }}" class="group rounded-2xl border border-stone-200 bg-white p-6 shadow-sm hover:shadow-md hover:border-stone-300 transition">
                <div class="flex items-start justify-between mb-3">
                    <div class="rounded-xl bg-stone-100 p-2.5 ring-1 ring-stone-200">
                        <svg class="h-5 w-5 text-stone-700" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m2.25 0H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z"/>
                        </svg>
                    </div>
                    <svg class="h-4 w-4 text-stone-300 group-hover:text-stone-500 group-hover:translate-x-0.5 transition" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5"/></svg>
                </div>
                <h3 class="font-semibold text-stone-900">SASCOC Report</h3>
                <p class="mt-1 text-sm text-stone-500">Compliance membership exports for SASCOC.</p>
            </a>
            @endrole

            {{-- Provincial Members --}}
            <a href="{{ route('provincial-members.index') }}" class="group rounded-2xl border border-stone-200 bg-white p-6 shadow-sm hover:shadow-md hover:border-stone-300 transition">
                <div class="flex items-start justify-between mb-3">
                    <div class="rounded-xl bg-stone-100 p-2.5 ring-1 ring-stone-200">
                        <svg class="h-5 w-5 text-stone-700" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 0 0 2.625.372 9.337 9.337 0 0 0 4.121-.952 4.125 4.125 0 0 0-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 0 1 8.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0 1 11.964-3.07M12 6.375a3.375 3.375 0 1 1-6.75 0 3.375 3.375 0 0 1 6.75 0Zm8.25 2.25a2.625 2.625 0 1 1-5.25 0 2.625 2.625 0 0 1 5.25 0Z"/>
                        </svg>
                    </div>
                    <svg class="h-4 w-4 text-stone-300 group-hover:text-stone-500 group-hover:translate-x-0.5 transition" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5"/></svg>
                </div>
                <h3 class="font-semibold text-stone-900">Provincial Members</h3>
                <p class="mt-1 text-sm text-stone-500">Member directory by province with CSV export.</p>
            </a>
        </div>
    </div>
</x-layouts.app>
