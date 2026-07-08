<x-layouts.app :title="'SASCOC Federation Report - SAPRF'">
    @php
        $downloadParams = [
            'year' => $year,
            'include_expired' => $includeExpired ? 1 : 0,
            'senior_price' => $seniorPrice,
            'junior_price' => $juniorPrice,
            'issue_date' => $issueDate,
        ];
    @endphp
    <div class="space-y-6">
        <div>
            <h1 class="font-heading text-3xl font-bold text-stone-900 tracking-tight">SASCOC Federation Report</h1>
            <p class="mt-1 text-sm text-stone-500">Generate the SASCOC membership template. Junior = under 18 on the date of issue; everyone else is a senior. Only paid federation members are included.</p>
        </div>

        <form method="GET" action="{{ route('sascoc-report.index') }}" class="rounded-xl border border-stone-200 bg-white p-6 shadow-sm space-y-4">
            <div class="flex flex-wrap items-end gap-4">
                <div>
                    <label for="year" class="block text-sm font-medium text-stone-700 mb-1">Reporting Year</label>
                    <select name="year" id="year"
                        class="rounded-lg border border-stone-300 text-sm py-2.5 px-3 focus:ring-emerald-500 focus:border-emerald-500">
                        @foreach ($years as $y)
                            <option value="{{ $y }}" @selected($y == $year)>{{ $y }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="issue_date" class="block text-sm font-medium text-stone-700 mb-1">Date of issue</label>
                    <input type="date" name="issue_date" id="issue_date" value="{{ $issueDate }}"
                        class="rounded-lg border border-stone-300 text-sm py-2.5 px-3 focus:ring-emerald-500 focus:border-emerald-500">
                </div>
                <div>
                    <label for="senior_price" class="block text-sm font-medium text-stone-700 mb-1">Senior price (R)</label>
                    <input type="number" step="0.01" min="0" name="senior_price" id="senior_price" value="{{ $seniorPrice }}"
                        class="w-32 rounded-lg border border-stone-300 text-sm py-2.5 px-3 focus:ring-emerald-500 focus:border-emerald-500">
                </div>
                <div>
                    <label for="junior_price" class="block text-sm font-medium text-stone-700 mb-1">Junior price (R)</label>
                    <input type="number" step="0.01" min="0" name="junior_price" id="junior_price" value="{{ $juniorPrice }}"
                        class="w-32 rounded-lg border border-stone-300 text-sm py-2.5 px-3 focus:ring-emerald-500 focus:border-emerald-500">
                </div>
                <button type="submit"
                    class="rounded-lg bg-stone-100 px-5 py-2.5 text-sm font-medium text-stone-700 hover:bg-stone-200 transition">
                    Apply
                </button>
            </div>
            <label class="flex items-center gap-2 cursor-pointer">
                <input type="checkbox" name="include_expired" value="1"
                       class="rounded border border-stone-300 text-emerald-600 focus:ring-emerald-500"
                       @checked($includeExpired)>
                <span class="text-sm font-medium text-stone-700">Include members who expired during {{ $year }}</span>
            </label>
        </form>

        <div class="grid grid-cols-2 sm:grid-cols-4 gap-4">
            <div class="rounded-xl border border-stone-200 bg-white shadow-sm p-5">
                <p class="text-2xl font-bold text-stone-900">{{ number_format($memberCount) }}</p>
                <p class="text-xs text-stone-500">total members</p>
            </div>
            <div class="rounded-xl border border-stone-200 bg-white shadow-sm p-5">
                <p class="text-2xl font-bold text-emerald-700">{{ number_format($seniorCount) }}</p>
                <p class="text-xs text-stone-500">seniors &times; R{{ number_format($seniorPrice, 2) }}</p>
            </div>
            <div class="rounded-xl border border-stone-200 bg-white shadow-sm p-5">
                <p class="text-2xl font-bold text-sky-700">{{ number_format($juniorCount) }}</p>
                <p class="text-xs text-stone-500">juniors &times; R{{ number_format($juniorPrice, 2) }}</p>
            </div>
            <div class="rounded-xl border border-stone-200 bg-white shadow-sm p-5">
                <p class="text-2xl font-bold text-stone-900">R{{ number_format($total, 2) }}</p>
                <p class="text-xs text-stone-500">total due</p>
            </div>
        </div>

        @if ($missingIdCount > 0)
            <div class="rounded-xl border border-amber-200 bg-amber-50 p-4 flex items-start gap-3">
                <svg class="h-5 w-5 text-amber-600 mt-0.5 shrink-0" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z" /></svg>
                <div>
                    <p class="text-sm font-semibold text-amber-800">{{ $missingIdCount }} {{ Str::plural('member', $missingIdCount) }} missing SA ID numbers</p>
                    <p class="mt-0.5 text-xs text-amber-700">These members will appear in the report with blank ID and gender fields. Update their profiles before exporting if possible.</p>
                </div>
            </div>
        @endif

        @if($memberCount > 0)
        <div class="flex flex-wrap gap-3">
            <a href="{{ route('sascoc-report.excel', $downloadParams) }}"
                class="inline-flex items-center gap-2 rounded-xl bg-emerald-700 px-5 py-2.5 text-sm font-semibold text-white hover:bg-emerald-800 transition">
                <svg class="h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5M16.5 12 12 16.5m0 0L7.5 12m4.5 4.5V3" /></svg>
                Download Template (CSV)
            </a>
            <a href="{{ route('sascoc-report.pdf', $downloadParams) }}"
                class="inline-flex items-center gap-2 rounded-xl bg-emerald-700 px-5 py-2.5 text-sm font-semibold text-white hover:bg-emerald-800 transition">
                <svg class="h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5M16.5 12 12 16.5m0 0L7.5 12m4.5 4.5V3" /></svg>
                Download PDF
            </a>
        </div>
        @else
        <div class="rounded-xl border border-stone-200 bg-stone-50 p-6 text-center">
            <p class="text-sm text-stone-500">No qualifying members found for {{ $year }} with the current filters.</p>
        </div>
        @endif
    </div>
</x-layouts.app>
