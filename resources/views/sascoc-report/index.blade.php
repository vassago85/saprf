<x-layouts.app :title="'SASCOC Annual Report - SAPRF'">
    <div class="space-y-6">
        <div>
            <h1 class="font-heading text-3xl font-bold text-stone-900 tracking-tight">SASCOC Annual Report</h1>
            <p class="mt-1 text-sm text-stone-500">Generate the annual active-member report required by SASCOC.</p>
        </div>

        <form method="GET" action="{{ route('sascoc-report.index') }}" class="flex items-end gap-4">
            <div>
                <label for="year" class="block text-sm font-medium text-stone-700 mb-1">Reporting Year</label>
                <select name="year" id="year"
                    class="rounded-lg border-stone-300 text-sm py-2.5 px-3 focus:ring-emerald-500 focus:border-emerald-500">
                    @foreach ($years as $y)
                        <option value="{{ $y }}" @selected($y == $year)>{{ $y }}</option>
                    @endforeach
                </select>
            </div>
            <button type="submit"
                class="rounded-lg bg-stone-100 px-5 py-2.5 text-sm font-medium text-stone-700 hover:bg-stone-200 transition">
                Apply
            </button>
        </form>

        <div class="rounded-xl border border-stone-200 bg-white shadow-sm p-6">
            <div class="flex items-center gap-3">
                <div class="flex h-10 w-10 items-center justify-center rounded-full bg-emerald-100">
                    <svg class="h-5 w-5 text-emerald-700" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 0 0 2.625.372 9.337 9.337 0 0 0 4.121-.952 4.125 4.125 0 0 0-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 0 1 8.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0 1 11.964-3.07M12 6.375a3.375 3.375 0 1 1-6.75 0 3.375 3.375 0 0 1 6.75 0Zm8.25 2.25a2.625 2.625 0 1 1-5.25 0 2.625 2.625 0 0 1 5.25 0Z" /></svg>
                </div>
                <div>
                    <p class="text-2xl font-bold text-stone-900">{{ $memberCount }}</p>
                    <p class="text-sm text-stone-500">active paid members found for {{ $year }}</p>
                </div>
            </div>
        </div>

        @if ($missingIdCount > 0)
            <div class="rounded-xl border border-amber-200 bg-amber-50 p-4 flex items-start gap-3">
                <svg class="h-5 w-5 text-amber-600 mt-0.5 shrink-0" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z" /></svg>
                <div>
                    <p class="text-sm font-semibold text-amber-800">{{ $missingIdCount }} {{ Str::plural('member', $missingIdCount) }} missing SA ID numbers</p>
                    <p class="mt-0.5 text-xs text-amber-700">These members will appear in the report with blank ID fields. Update their profiles before exporting if possible.</p>
                </div>
            </div>
        @endif

        <div class="flex flex-wrap gap-3">
            <a href="{{ route('sascoc-report.excel', ['year' => $year]) }}"
                class="inline-flex items-center gap-2 rounded-xl bg-emerald-700 px-5 py-2.5 text-sm font-semibold text-white hover:bg-emerald-800 transition">
                <svg class="h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5M16.5 12 12 16.5m0 0L7.5 12m4.5 4.5V3" /></svg>
                Download Excel (CSV)
            </a>
            <a href="{{ route('sascoc-report.pdf', ['year' => $year]) }}"
                class="inline-flex items-center gap-2 rounded-xl bg-emerald-700 px-5 py-2.5 text-sm font-semibold text-white hover:bg-emerald-800 transition">
                <svg class="h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5M16.5 12 12 16.5m0 0L7.5 12m4.5 4.5V3" /></svg>
                Download PDF
            </a>
        </div>
    </div>
</x-layouts.app>
