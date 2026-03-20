<x-layouts.app :title="'Audit Logs'">
    <h1 class="font-heading text-3xl font-bold text-stone-900">Audit Logs</h1>

    <div class="mt-8 overflow-x-auto rounded-xl border border-stone-200 bg-white shadow-sm">
        <table class="min-w-full">
            <thead>
                <tr class="border-b-2 border-stone-200 bg-stone-50">
                    <th class="px-5 py-3.5 text-left text-xs font-semibold uppercase tracking-wider text-stone-500">Date</th>
                    <th class="px-5 py-3.5 text-left text-xs font-semibold uppercase tracking-wider text-stone-500">User</th>
                    <th class="px-5 py-3.5 text-left text-xs font-semibold uppercase tracking-wider text-stone-500">Action</th>
                    <th class="px-5 py-3.5 text-left text-xs font-semibold uppercase tracking-wider text-stone-500">Entity Type</th>
                    <th class="px-5 py-3.5 text-left text-xs font-semibold uppercase tracking-wider text-stone-500">Entity ID</th>
                    <th class="px-5 py-3.5 text-right text-xs font-semibold uppercase tracking-wider text-stone-500">Details</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-stone-100">
                @forelse ($auditLogs as $log)
                    <tr class="hover:bg-stone-50 transition-colors">
                        <td class="whitespace-nowrap px-5 py-3.5 text-sm text-stone-500">{{ $log->created_at->format('d M Y H:i:s') }}</td>
                        <td class="whitespace-nowrap px-5 py-3.5 text-sm text-stone-900">{{ $log->user->name ?? 'System' }}</td>
                        <td class="whitespace-nowrap px-5 py-3.5 text-sm">
                            @switch($log->action)
                                @case('created')
                                    <span class="inline-flex items-center rounded-full bg-emerald-50 px-2.5 py-0.5 text-xs font-semibold text-emerald-700 ring-1 ring-inset ring-emerald-600/20">Created</span>
                                    @break
                                @case('updated')
                                    <span class="inline-flex items-center rounded-full bg-sky-50 px-2.5 py-0.5 text-xs font-semibold text-sky-700 ring-1 ring-inset ring-sky-600/20">Updated</span>
                                    @break
                                @case('deleted')
                                    <span class="inline-flex items-center rounded-full bg-red-50 px-2.5 py-0.5 text-xs font-semibold text-red-700 ring-1 ring-inset ring-red-600/20">Deleted</span>
                                    @break
                                @default
                                    <span class="inline-flex items-center rounded-full bg-stone-100 px-2.5 py-0.5 text-xs font-semibold text-stone-600 ring-1 ring-inset ring-stone-500/20">{{ ucfirst($log->action) }}</span>
                            @endswitch
                        </td>
                        <td class="whitespace-nowrap px-5 py-3.5 text-sm text-stone-500">{{ class_basename($log->entity_type) }}</td>
                        <td class="whitespace-nowrap px-5 py-3.5 text-sm font-mono text-stone-500">{{ $log->entity_id }}</td>
                        <td class="whitespace-nowrap px-5 py-3.5 text-right text-sm">
                            <a href="{{ route('audit-logs.show', $log) }}" class="rounded-lg p-1.5 text-stone-400 hover:bg-stone-100 hover:text-stone-700" title="View">
                                <svg class="inline h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 0 1 0-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178Z" /><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" /></svg>
                            </a>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="px-5 py-12 text-center text-sm text-stone-400">No audit logs found.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-6">
        {{ $auditLogs->links() }}
    </div>
</x-layouts.app>
