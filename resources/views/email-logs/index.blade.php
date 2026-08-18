<x-layouts.app :title="'Email Log'">
    <div class="flex flex-wrap items-baseline justify-between gap-4">
        <div>
            <h1 class="font-heading text-3xl font-bold text-stone-900">Email Log</h1>
            <p class="mt-1 text-sm text-stone-500">Every email the app has attempted to send. Live delivery status is driven by Mailgun webhooks.</p>
        </div>
        <a href="{{ route('email-logs.index') }}"
            class="rounded-lg border border-stone-200 bg-white px-3.5 py-2 text-sm font-semibold text-stone-700 shadow-sm hover:bg-stone-50">
            Reset filters
        </a>
    </div>

    @php
        $statusTabs = [
            null => ['label' => 'All',       'count' => (int) $counts->sum(),                                        'classes' => 'bg-stone-100 text-stone-700'],
            \App\Models\EmailLog::STATUS_QUEUED     => ['label' => 'Queued',     'count' => (int) ($counts[\App\Models\EmailLog::STATUS_QUEUED] ?? 0),     'classes' => 'bg-stone-100 text-stone-700'],
            \App\Models\EmailLog::STATUS_SENT       => ['label' => 'Sent',       'count' => (int) ($counts[\App\Models\EmailLog::STATUS_SENT] ?? 0),       'classes' => 'bg-sky-100 text-sky-800'],
            \App\Models\EmailLog::STATUS_DELIVERED  => ['label' => 'Delivered',  'count' => (int) ($counts[\App\Models\EmailLog::STATUS_DELIVERED] ?? 0),  'classes' => 'bg-emerald-100 text-emerald-800'],
            \App\Models\EmailLog::STATUS_FAILED     => ['label' => 'Failed',     'count' => (int) ($counts[\App\Models\EmailLog::STATUS_FAILED] ?? 0),     'classes' => 'bg-amber-100 text-amber-800'],
            \App\Models\EmailLog::STATUS_BOUNCED    => ['label' => 'Bounced',    'count' => (int) ($counts[\App\Models\EmailLog::STATUS_BOUNCED] ?? 0),    'classes' => 'bg-rose-100 text-rose-800'],
            \App\Models\EmailLog::STATUS_COMPLAINED => ['label' => 'Complained', 'count' => (int) ($counts[\App\Models\EmailLog::STATUS_COMPLAINED] ?? 0), 'classes' => 'bg-rose-100 text-rose-800'],
        ];
    @endphp

    <div class="mt-6 flex flex-wrap gap-2">
        @foreach($statusTabs as $key => $tab)
            @php $active = ($status ?? null) === $key; @endphp
            <a href="{{ route('email-logs.index', array_filter(['status' => $key, 'search' => $search, 'notification_class' => $notificationClass])) }}"
                class="inline-flex items-center gap-2 rounded-lg border px-3.5 py-2 text-sm font-semibold transition {{ $active ? 'border-emerald-300 bg-emerald-50 text-emerald-700' : 'border-stone-200 bg-white text-stone-600 hover:bg-stone-50' }}">
                {{ $tab['label'] }}
                <span class="rounded-full {{ $tab['classes'] }} px-2 py-0.5 text-xs font-medium">{{ $tab['count'] }}</span>
            </a>
        @endforeach
    </div>

    <form method="GET" action="{{ route('email-logs.index') }}" class="mt-4 flex flex-wrap items-end gap-3 rounded-xl border border-stone-200 bg-white p-4 shadow-sm">
        <input type="hidden" name="status" value="{{ $status }}">
        <div class="flex-1 min-w-[220px]">
            <label for="search" class="block text-xs font-medium text-stone-500">Recipient contains</label>
            <input type="text" name="search" id="search" value="{{ $search }}" placeholder="e.g. gmail.com"
                class="mt-1 block w-full rounded-lg border border-stone-300 px-3 py-2 text-sm text-stone-900 shadow-sm focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500">
        </div>
        <div class="min-w-[220px]">
            <label for="notification_class" class="block text-xs font-medium text-stone-500">Notification type</label>
            <select name="notification_class" id="notification_class"
                class="mt-1 block w-full rounded-lg border border-stone-300 px-3 py-2 text-sm text-stone-900 shadow-sm focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500">
                <option value="">All types</option>
                @foreach($notificationClasses as $cls => $count)
                    <option value="{{ $cls }}" @selected($notificationClass === $cls)>{{ class_basename($cls) }} ({{ $count }})</option>
                @endforeach
            </select>
        </div>
        <button class="rounded-lg bg-emerald-700 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-800">Filter</button>
    </form>

    <div class="mt-6 overflow-x-auto rounded-xl border border-stone-200 bg-white shadow-sm">
        <table class="min-w-full">
            <thead>
                <tr class="border-b-2 border-stone-200 bg-stone-50">
                    <th class="px-5 py-3.5 text-left text-xs font-semibold uppercase tracking-wider text-stone-500">Sent</th>
                    <th class="px-5 py-3.5 text-left text-xs font-semibold uppercase tracking-wider text-stone-500">Recipient</th>
                    <th class="px-5 py-3.5 text-left text-xs font-semibold uppercase tracking-wider text-stone-500">Subject</th>
                    <th class="px-5 py-3.5 text-left text-xs font-semibold uppercase tracking-wider text-stone-500">Type</th>
                    <th class="px-5 py-3.5 text-left text-xs font-semibold uppercase tracking-wider text-stone-500">Status</th>
                    <th class="px-5 py-3.5 text-right text-xs font-semibold uppercase tracking-wider text-stone-500">Details</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-stone-100">
                @forelse ($logs as $log)
                    <tr class="hover:bg-stone-50 transition-colors">
                        <td class="whitespace-nowrap px-5 py-3.5 text-sm text-stone-500">
                            {{ ($log->sent_at ?? $log->created_at)->format('d M Y H:i:s') }}
                        </td>
                        <td class="whitespace-nowrap px-5 py-3.5 text-sm text-stone-900">
                            {{ $log->to_email }}
                            @if ($log->to_name)
                                <div class="text-xs text-stone-400">{{ $log->to_name }}</div>
                            @endif
                        </td>
                        <td class="px-5 py-3.5 text-sm text-stone-900">
                            <span class="line-clamp-1">{{ $log->subject }}</span>
                        </td>
                        <td class="whitespace-nowrap px-5 py-3.5 text-xs text-stone-500">
                            {{ $log->notification_class ? class_basename($log->notification_class) : 'Ad-hoc' }}
                        </td>
                        <td class="whitespace-nowrap px-5 py-3.5 text-sm">
                            <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-semibold ring-1 ring-inset {{ $log->statusPillClasses() }}">
                                {{ ucfirst($log->status) }}
                            </span>
                        </td>
                        <td class="whitespace-nowrap px-5 py-3.5 text-right text-sm">
                            <a href="{{ route('email-logs.show', $log) }}" class="text-emerald-700 hover:text-emerald-800 font-semibold">View</a>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="px-5 py-12 text-center text-sm text-stone-400">No emails match your filters.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">
        {{ $logs->links() }}
    </div>
</x-layouts.app>
