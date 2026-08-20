<x-layouts.app :title="'Audit Logs'">
    <h1 class="font-heading text-3xl font-bold text-stone-900">Audit Logs</h1>

    @php
        $counts = $counts ?? collect();
        $total = (int) $counts->sum();
        $tabs = [
            null => ['label' => 'All', 'count' => $total],
            \App\Models\AuditLog::ACTOR_USER => ['label' => 'User changes', 'count' => (int) ($counts[\App\Models\AuditLog::ACTOR_USER] ?? 0)],
            \App\Models\AuditLog::ACTOR_ADMIN => ['label' => 'Admin changes', 'count' => (int) ($counts[\App\Models\AuditLog::ACTOR_ADMIN] ?? 0)],
            \App\Models\AuditLog::ACTOR_SYSTEM => ['label' => 'System changes', 'count' => (int) ($counts[\App\Models\AuditLog::ACTOR_SYSTEM] ?? 0)],
        ];
    @endphp

    <div class="mt-6 flex flex-wrap gap-2">
        @foreach($tabs as $key => $tab)
            @php $active = ($category ?? null) === $key; @endphp
            <a href="{{ route('audit-logs.index', $key ? ['category' => $key] : []) }}"
               class="inline-flex items-center gap-2 rounded-lg border px-3.5 py-2 text-sm font-semibold transition {{ $active ? 'border-emerald-300 bg-emerald-50 text-emerald-700' : 'border-stone-200 bg-white text-stone-600 hover:bg-stone-50' }}">
                {{ $tab['label'] }}
                <span class="rounded-full {{ $active ? 'bg-emerald-100 text-emerald-700' : 'bg-stone-100 text-stone-500' }} px-2 py-0.5 text-xs font-medium">{{ $tab['count'] }}</span>
            </a>
        @endforeach
    </div>

    <div class="mt-6 overflow-x-auto rounded-xl border border-stone-200 bg-white shadow-sm">
        <table class="min-w-full">
            <thead>
                <tr class="border-b-2 border-stone-200 bg-stone-50">
                    <th class="px-5 py-3.5 text-left text-xs font-semibold uppercase tracking-wider text-stone-500">Date</th>
                    <th class="px-5 py-3.5 text-left text-xs font-semibold uppercase tracking-wider text-stone-500">Source</th>
                    <th class="px-5 py-3.5 text-left text-xs font-semibold uppercase tracking-wider text-stone-500">User</th>
                    <th class="px-5 py-3.5 text-left text-xs font-semibold uppercase tracking-wider text-stone-500">Action</th>
                    <th class="px-5 py-3.5 text-left text-xs font-semibold uppercase tracking-wider text-stone-500">Entity</th>
                    <th class="px-5 py-3.5 text-left text-xs font-semibold uppercase tracking-wider text-stone-500">Subject</th>
                    <th class="px-5 py-3.5 text-right text-xs font-semibold uppercase tracking-wider text-stone-500">Details</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-stone-100">
                @forelse ($auditLogs as $log)
                    <tr class="hover:bg-stone-50 transition-colors">
                        <td class="whitespace-nowrap px-5 py-3.5 text-sm text-stone-500">{{ $log->created_at->format('d M Y H:i:s') }}</td>
                        <td class="whitespace-nowrap px-5 py-3.5 text-sm">
                            @switch($log->displayActorType($revealImpersonation))
                                @case(\App\Models\AuditLog::ACTOR_SYSTEM)
                                    <span class="inline-flex items-center rounded-full bg-stone-100 px-2.5 py-0.5 text-xs font-semibold text-stone-600 ring-1 ring-inset ring-stone-500/20">System</span>
                                    @break
                                @case(\App\Models\AuditLog::ACTOR_ADMIN)
                                    <span class="inline-flex items-center rounded-full bg-violet-50 px-2.5 py-0.5 text-xs font-semibold text-violet-700 ring-1 ring-inset ring-violet-600/20">Admin</span>
                                    @break
                                @default
                                    <span class="inline-flex items-center rounded-full bg-blue-50 px-2.5 py-0.5 text-xs font-semibold text-blue-700 ring-1 ring-inset ring-blue-600/20">User</span>
                            @endswitch
                        </td>
                        <td class="px-5 py-3.5 text-sm text-stone-900">
                            <div>{{ $log->displayActorName($revealImpersonation) }}</div>
                            @if($revealImpersonation && $log->wasImpersonated())
                                <div class="text-xs text-red-700 mt-0.5">
                                    acting as {{ $log->impersonatedUser?->name ?? ('#'.$log->impersonated_user_id) }}
                                </div>
                            @endif
                        </td>
                        <td class="whitespace-nowrap px-5 py-3.5 text-sm">
                            @switch($log->action_type)
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
                                    <span class="inline-flex items-center rounded-full bg-stone-100 px-2.5 py-0.5 text-xs font-semibold text-stone-600 ring-1 ring-inset ring-stone-500/20">{{ ucfirst($log->action_type) }}</span>
                            @endswitch
                        </td>
                        <td class="whitespace-nowrap px-5 py-3.5 text-sm text-stone-600">
                            <div>{{ class_basename($log->entity_type) }}</div>
                            @if($log->entity_id)
                                <div class="text-xs font-mono text-stone-400">#{{ $log->entity_id }}</div>
                            @endif
                        </td>
                        <td class="px-5 py-3.5 text-sm text-stone-900">
                            @php $subject = $log->resolveSubject(); @endphp
                            @if($subject)
                                <div class="font-medium">
                                    {{ $subject['name'] }}
                                    @if($subject['is_deleted'])
                                        <span class="ml-1 inline-flex items-center rounded-full bg-red-50 px-1.5 py-0 text-[10px] font-semibold text-red-700 ring-1 ring-inset ring-red-600/20">Deleted</span>
                                    @endif
                                </div>
                                @if(! empty($subject['saprf_number']))
                                    <div class="text-xs text-stone-500">{{ $subject['saprf_number'] }}</div>
                                @elseif(! empty($subject['email']))
                                    <div class="text-xs text-stone-500 truncate max-w-xs">{{ $subject['email'] }}</div>
                                @endif
                            @else
                                <span class="text-stone-300">—</span>
                            @endif
                        </td>
                        <td class="whitespace-nowrap px-5 py-3.5 text-right text-sm">
                            <a href="{{ route('audit-logs.show', $log) }}" class="rounded-lg p-1.5 text-stone-400 hover:bg-stone-100 hover:text-stone-700" title="View">
                                <svg class="inline h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 0 1 0-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178Z" /><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" /></svg>
                            </a>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="px-5 py-12 text-center text-sm text-stone-400">No audit logs found.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-6">
        {{ $auditLogs->links() }}
    </div>
</x-layouts.app>
