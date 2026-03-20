@props(['discipline' => null])

<div x-data="eventsCalendar('{{ $discipline }}')" x-init="loadMonth()" class="space-y-4">
    {{-- Calendar header --}}
    <div class="bg-white rounded-2xl border border-stone-200 shadow-sm overflow-hidden">
        {{-- Month navigation --}}
        <div class="flex items-center justify-between px-6 py-4 border-b border-stone-100">
            <button @click="prevMonth()" class="p-2 rounded-lg hover:bg-stone-100 transition text-stone-500 hover:text-stone-700">
                <svg class="size-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5 8.25 12l7.5-7.5" /></svg>
            </button>
            <h2 class="font-heading text-xl font-bold text-stone-900" x-text="monthLabel"></h2>
            <button @click="nextMonth()" class="p-2 rounded-lg hover:bg-stone-100 transition text-stone-500 hover:text-stone-700">
                <svg class="size-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5" /></svg>
            </button>
        </div>

        {{-- Day headers --}}
        <div class="grid grid-cols-7 border-b border-stone-100">
            <template x-for="day in ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun']">
                <div class="px-2 py-2.5 text-center text-[11px] font-semibold uppercase tracking-wider text-stone-400" x-text="day"></div>
            </template>
        </div>

        {{-- Calendar grid --}}
        <div class="grid grid-cols-7">
            <template x-for="(cell, idx) in calendarCells" :key="idx">
                <div @click="cell.date && selectDate(cell.date)"
                     :class="{
                         'bg-stone-50/50': !cell.isCurrentMonth,
                         'bg-white': cell.isCurrentMonth,
                         'ring-2 ring-inset ring-emerald-500 bg-emerald-50/50': cell.date === selectedDate,
                         'cursor-pointer hover:bg-stone-50': cell.date && cell.isCurrentMonth,
                     }"
                     class="relative min-h-[80px] sm:min-h-[100px] border-b border-r border-stone-100 p-1.5 sm:p-2 transition">

                    {{-- Day number --}}
                    <div class="flex items-center justify-between mb-1">
                        <span :class="{
                            'text-stone-300': !cell.isCurrentMonth,
                            'text-stone-700': cell.isCurrentMonth && !cell.isToday,
                            'bg-emerald-700 text-white rounded-full size-7 flex items-center justify-center': cell.isToday,
                        }" class="text-sm font-medium" x-text="cell.dayNum"></span>
                    </div>

                    {{-- Event dots --}}
                    <template x-if="cell.events && cell.events.length > 0">
                        <div class="space-y-0.5">
                            <template x-for="ev in cell.events.slice(0, 3)" :key="ev.id">
                                <div :class="{
                                    'bg-emerald-100 text-emerald-800': ev.match_type === 'PRS',
                                    'bg-sky-100 text-sky-800': ev.match_type === 'PR22',
                                    'bg-stone-100 text-stone-600': ev.match_type !== 'PRS' && ev.match_type !== 'PR22',
                                }" class="rounded px-1.5 py-0.5 text-[10px] font-medium truncate leading-tight cursor-pointer">
                                    <span x-text="ev.name" class="hidden sm:inline"></span>
                                    <span x-text="ev.match_type" class="sm:hidden"></span>
                                </div>
                            </template>
                            <template x-if="cell.events.length > 3">
                                <div class="text-[10px] text-stone-400 font-medium px-1" x-text="'+' + (cell.events.length - 3) + ' more'"></div>
                            </template>
                        </div>
                    </template>
                </div>
            </template>
        </div>
    </div>

    {{-- Selected date detail panel --}}
    <div x-show="selectedDate && selectedEvents.length > 0" x-cloak
         x-transition:enter="transition ease-out duration-200"
         x-transition:enter-start="opacity-0 translate-y-2"
         x-transition:enter-end="opacity-100 translate-y-0"
         class="bg-white rounded-2xl border border-stone-200 shadow-sm overflow-hidden">
        <div class="px-6 py-4 border-b border-stone-100 flex items-center justify-between">
            <h3 class="font-semibold text-stone-900" x-text="selectedDateLabel"></h3>
            <button @click="selectedDate = null" class="p-1 rounded-lg hover:bg-stone-100 transition text-stone-400">
                <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" /></svg>
            </button>
        </div>
        <div class="divide-y divide-stone-100">
            <template x-for="ev in selectedEvents" :key="ev.id">
                <div class="px-6 py-4 flex flex-col sm:flex-row sm:items-start gap-3">
                    <div class="flex-1 min-w-0">
                        <div class="flex items-center gap-2 mb-1">
                            <span :class="{
                                'bg-emerald-100 text-emerald-800': ev.match_type === 'PRS',
                                'bg-sky-100 text-sky-800': ev.match_type === 'PR22',
                            }" class="inline-flex items-center rounded-full px-2 py-0.5 text-[10px] font-semibold" x-text="ev.match_type"></span>
                            <span class="inline-flex items-center rounded-full bg-stone-100 px-2 py-0.5 text-[10px] font-medium text-stone-600 capitalize" x-text="ev.series_level"></span>
                            <span :class="{
                                'text-emerald-600': ev.status === 'open',
                                'text-amber-600': ev.status === 'waitlist' || ev.status === 'upcoming',
                                'text-stone-400': ev.status === 'closed',
                                'text-red-500': ev.status === 'full' || ev.status === 'cancelled',
                            }" class="text-[10px] font-bold uppercase tracking-wider" x-text="ev.status"></span>
                        </div>
                        <a :href="'/events/' + ev.id" class="text-sm font-semibold text-stone-900 hover:text-emerald-800 transition" x-text="ev.name"></a>
                        <div class="mt-1.5 space-y-0.5">
                            <template x-if="ev.venue_name">
                                <p class="flex items-center gap-1.5 text-xs text-stone-700">
                                    <svg class="size-3 text-stone-400 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15 10.5a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" /><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 10.5c0 7.142-7.5 11.25-7.5 11.25S4.5 17.642 4.5 10.5a7.5 7.5 0 0 1 15 0Z" /></svg>
                                    <span x-text="ev.venue_name" class="font-medium"></span>
                                </p>
                            </template>
                            <p class="flex items-center gap-1.5 text-xs text-stone-500">
                                <svg class="size-3 text-stone-300 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9 6.75V15m6-6v8.25m.503 3.498 4.875-2.437c.381-.19.622-.58.622-1.006V4.82c0-.836-.88-1.38-1.628-1.006l-3.869 1.934c-.317.159-.69.159-1.006 0L9.503 3.252a1.125 1.125 0 0 0-1.006 0L3.622 5.689C3.24 5.88 3 6.27 3 6.695V19.18c0 .836.88 1.38 1.628 1.006l3.869-1.934c.317-.159.69-.159 1.006 0l4.994 2.497c.317.158.69.158 1.006 0Z" /></svg>
                                <span x-text="ev.location || ev.province || 'TBC'"></span>
                            </p>
                            <template x-if="ev.md">
                                <p class="flex items-center gap-1.5 text-xs text-stone-500">
                                    <svg class="size-3 text-stone-300 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0ZM4.501 20.118a7.5 7.5 0 0 1 14.998 0A17.933 17.933 0 0 1 12 21.75c-2.676 0-5.216-.584-7.499-1.632Z" /></svg>
                                    <span>MD: <span x-text="ev.md"></span></span>
                                </p>
                            </template>
                        </div>
                    </div>
                    <div class="flex items-center gap-2 shrink-0 sm:pt-1">
                        <template x-if="ev.member_fee > 0">
                            <span class="text-xs text-stone-500">R<span x-text="ev.member_fee" class="font-medium text-stone-700"></span></span>
                        </template>
                        <template x-if="ev.status === 'open'">
                            <a :href="'/events/' + ev.id + '/register'"
                               class="px-4 py-1.5 rounded-lg bg-emerald-700 text-white text-xs font-semibold hover:bg-emerald-800 transition shadow-sm">
                                Register
                            </a>
                        </template>
                        <a :href="'/events/' + ev.id"
                           class="px-3 py-1.5 rounded-lg text-xs font-medium text-stone-600 hover:bg-stone-50 ring-1 ring-inset ring-stone-200 transition">
                            Details
                        </a>
                    </div>
                </div>
            </template>
        </div>
    </div>
</div>

<script>
document.addEventListener('alpine:init', () => {
    Alpine.data('eventsCalendar', (discipline) => ({
        currentMonth: new Date().getMonth(),
        currentYear: new Date().getFullYear(),
        events: {},
        loading: false,
        selectedDate: null,
        calendarCells: [],

        get monthLabel() {
            return new Date(this.currentYear, this.currentMonth).toLocaleDateString('en-ZA', { month: 'long', year: 'numeric' });
        },

        get selectedDateLabel() {
            if (!this.selectedDate) return '';
            const d = new Date(this.selectedDate + 'T12:00:00');
            return d.toLocaleDateString('en-ZA', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' });
        },

        get selectedEvents() {
            return this.events[this.selectedDate] || [];
        },

        prevMonth() {
            this.currentMonth--;
            if (this.currentMonth < 0) { this.currentMonth = 11; this.currentYear--; }
            this.selectedDate = null;
            this.loadMonth();
        },

        nextMonth() {
            this.currentMonth++;
            if (this.currentMonth > 11) { this.currentMonth = 0; this.currentYear++; }
            this.selectedDate = null;
            this.loadMonth();
        },

        selectDate(date) {
            this.selectedDate = this.selectedDate === date ? null : date;
        },

        async loadMonth() {
            this.loading = true;
            const params = new URLSearchParams({
                month: this.currentMonth + 1,
                year: this.currentYear,
            });
            if (discipline) params.set('discipline', discipline);

            try {
                const res = await fetch('/api/v1/events/calendar?' + params.toString());
                const data = await res.json();
                this.events = data.events || {};
            } catch (e) {
                this.events = {};
            }
            this.buildCells();
            this.loading = false;
        },

        buildCells() {
            const cells = [];
            const firstDay = new Date(this.currentYear, this.currentMonth, 1);
            let startDow = firstDay.getDay();
            if (startDow === 0) startDow = 7; // Mon=1 .. Sun=7

            const daysInMonth = new Date(this.currentYear, this.currentMonth + 1, 0).getDate();
            const daysInPrevMonth = new Date(this.currentYear, this.currentMonth, 0).getDate();
            const today = new Date();
            const todayStr = today.getFullYear() + '-' + String(today.getMonth() + 1).padStart(2, '0') + '-' + String(today.getDate()).padStart(2, '0');

            // Previous month padding
            for (let i = startDow - 2; i >= 0; i--) {
                const d = daysInPrevMonth - i;
                const m = this.currentMonth === 0 ? 12 : this.currentMonth;
                const y = this.currentMonth === 0 ? this.currentYear - 1 : this.currentYear;
                const dateStr = y + '-' + String(m).padStart(2, '0') + '-' + String(d).padStart(2, '0');
                cells.push({ dayNum: d, date: dateStr, isCurrentMonth: false, isToday: false, events: this.events[dateStr] || [] });
            }

            // Current month
            for (let d = 1; d <= daysInMonth; d++) {
                const dateStr = this.currentYear + '-' + String(this.currentMonth + 1).padStart(2, '0') + '-' + String(d).padStart(2, '0');
                cells.push({ dayNum: d, date: dateStr, isCurrentMonth: true, isToday: dateStr === todayStr, events: this.events[dateStr] || [] });
            }

            // Next month padding
            const remaining = 42 - cells.length;
            for (let d = 1; d <= remaining; d++) {
                const m = this.currentMonth + 2 > 12 ? 1 : this.currentMonth + 2;
                const y = this.currentMonth + 2 > 12 ? this.currentYear + 1 : this.currentYear;
                const dateStr = y + '-' + String(m).padStart(2, '0') + '-' + String(d).padStart(2, '0');
                cells.push({ dayNum: d, date: dateStr, isCurrentMonth: false, isToday: false, events: this.events[dateStr] || [] });
            }

            this.calendarCells = cells;
        }
    }));
});
</script>
