<x-layouts.app :title="'Audit Log #' . $auditLog->id">
    <div class="flex items-center justify-between">
        <h1 class="font-heading text-3xl font-bold text-stone-900">Audit Log #{{ $auditLog->id }}</h1>
        <a href="{{ route('audit-logs.index') }}" class="inline-flex items-center gap-2 rounded-lg px-4 py-2 text-sm font-medium text-stone-600 hover:bg-stone-100 hover:text-stone-900">
            <svg class="h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5 3 12m0 0 7.5-7.5M3 12h18" /></svg>
            Back
        </a>
    </div>

    <div class="mt-8 max-w-4xl space-y-6">
        <div class="rounded-xl border border-stone-200 bg-white p-6 shadow-sm">
            <h2 class="font-heading text-lg font-semibold text-stone-900 mb-5">Log Details</h2>

            <dl class="grid grid-cols-1 gap-x-6 gap-y-5 sm:grid-cols-2 lg:grid-cols-3">
                <div>
                    <dt class="text-xs font-semibold uppercase tracking-wide text-stone-400">Date</dt>
                    <dd class="mt-1 text-sm text-stone-900">{{ $auditLog->created_at->format('d M Y H:i:s') }}</dd>
                </div>
                <div>
                    <dt class="text-xs font-semibold uppercase tracking-wide text-stone-400">Source</dt>
                    <dd class="mt-1.5">
                        @switch($auditLog->actor_type)
                            @case(\App\Models\AuditLog::ACTOR_SYSTEM)
                                <span class="inline-flex items-center rounded-full bg-stone-100 px-2.5 py-0.5 text-xs font-semibold text-stone-600 ring-1 ring-inset ring-stone-500/20">System</span>
                                @break
                            @case(\App\Models\AuditLog::ACTOR_ADMIN)
                                <span class="inline-flex items-center rounded-full bg-violet-50 px-2.5 py-0.5 text-xs font-semibold text-violet-700 ring-1 ring-inset ring-violet-600/20">Admin</span>
                                @break
                            @default
                                <span class="inline-flex items-center rounded-full bg-blue-50 px-2.5 py-0.5 text-xs font-semibold text-blue-700 ring-1 ring-inset ring-blue-600/20">User</span>
                        @endswitch
                    </dd>
                </div>
                <div>
                    <dt class="text-xs font-semibold uppercase tracking-wide text-stone-400">User</dt>
                    <dd class="mt-1 text-sm text-stone-900">{{ $auditLog->user->name ?? 'System' }}</dd>
                </div>
                @if($auditLog->wasImpersonated())
                    <div>
                        <dt class="text-xs font-semibold uppercase tracking-wide text-stone-400">Acting as</dt>
                        <dd class="mt-1 text-sm text-red-700">
                            {{ $auditLog->impersonatedUser?->name ?? ('user #'.$auditLog->impersonated_user_id) }}
                            <span class="block text-xs text-stone-500 mt-0.5">Developer impersonation — the named user did not make this change.</span>
                        </dd>
                    </div>
                @endif
                <div>
                    <dt class="text-xs font-semibold uppercase tracking-wide text-stone-400">Action</dt>
                    <dd class="mt-1.5">
                        @switch($auditLog->action_type)
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
                                <span class="inline-flex items-center rounded-full bg-stone-100 px-2.5 py-0.5 text-xs font-semibold text-stone-600 ring-1 ring-inset ring-stone-500/20">{{ ucfirst($auditLog->action_type) }}</span>
                        @endswitch
                    </dd>
                </div>
                <div>
                    <dt class="text-xs font-semibold uppercase tracking-wide text-stone-400">Entity Type</dt>
                    <dd class="mt-1 text-sm text-stone-900">{{ class_basename($auditLog->entity_type) }}</dd>
                </div>
                <div>
                    <dt class="text-xs font-semibold uppercase tracking-wide text-stone-400">Entity ID</dt>
                    <dd class="mt-1 text-sm font-mono text-stone-900">{{ $auditLog->entity_id }}</dd>
                </div>
                @if ($auditLog->reason)
                    <div class="sm:col-span-2 lg:col-span-3">
                        <dt class="text-xs font-semibold uppercase tracking-wide text-stone-400">Reason</dt>
                        <dd class="mt-1 text-sm text-stone-900">{{ $auditLog->reason }}</dd>
                    </div>
                @endif
            </dl>
        </div>

        {{-- Subject: the person the log entry is ABOUT (distinct from the actor who made the change). --}}
        @if ($subject)
            <div class="rounded-xl border border-stone-200 bg-white p-6 shadow-sm">
                <div class="flex items-start justify-between gap-4">
                    <div class="flex-1">
                        <h2 class="font-heading text-lg font-semibold text-stone-900 mb-1">
                            Subject
                            <span class="text-xs font-normal text-stone-400 ml-1">— the account this change affected</span>
                        </h2>
                        <dl class="mt-4 grid grid-cols-1 gap-x-6 gap-y-4 sm:grid-cols-3">
                            <div>
                                <dt class="text-xs font-semibold uppercase tracking-wide text-stone-400">Name</dt>
                                <dd class="mt-1 text-sm font-medium text-stone-900">
                                    {{ $subject['name'] }}
                                    @if($subject['is_deleted'])
                                        <span class="ml-1 inline-flex items-center rounded-full bg-red-50 px-2 py-0.5 text-[11px] font-semibold text-red-700 ring-1 ring-inset ring-red-600/20">Deleted</span>
                                    @endif
                                </dd>
                            </div>
                            @if(! empty($subject['email']))
                                <div>
                                    <dt class="text-xs font-semibold uppercase tracking-wide text-stone-400">Email</dt>
                                    <dd class="mt-1 text-sm text-stone-900 break-all">{{ $subject['email'] }}</dd>
                                </div>
                            @endif
                            @if(! empty($subject['saprf_number']))
                                <div>
                                    <dt class="text-xs font-semibold uppercase tracking-wide text-stone-400">SAPRF #</dt>
                                    <dd class="mt-1 text-sm font-mono text-stone-900">{{ $subject['saprf_number'] }}</dd>
                                </div>
                            @endif
                        </dl>
                    </div>
                    @if(! empty($subject['edit_url']))
                        <a href="{{ $subject['edit_url'] }}"
                           class="inline-flex items-center gap-1.5 rounded-lg border border-stone-200 bg-white px-3 py-1.5 text-xs font-semibold text-stone-700 hover:bg-stone-50 whitespace-nowrap">
                            Open user
                            <svg class="size-3.5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5 21 12m0 0-7.5 7.5M21 12H3"/></svg>
                        </a>
                    @endif
                </div>
            </div>
        @endif

        @if ($auditLog->old_value)
            <div class="rounded-xl border border-stone-200 bg-white p-6 shadow-sm">
                <h2 class="font-heading text-lg font-semibold text-stone-900 mb-5">Old Values</h2>
                <pre class="overflow-x-auto rounded-lg bg-stone-50 border border-stone-200 p-4 text-sm text-stone-800"><code>{{ json_encode($auditLog->old_value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</code></pre>
            </div>
        @endif

        @if ($auditLog->new_value)
            <div class="rounded-xl border border-stone-200 bg-white p-6 shadow-sm">
                <h2 class="font-heading text-lg font-semibold text-stone-900 mb-5">New Values</h2>
                <pre class="overflow-x-auto rounded-lg bg-stone-50 border border-stone-200 p-4 text-sm text-stone-800"><code>{{ json_encode($auditLog->new_value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</code></pre>
            </div>
        @endif
    </div>
</x-layouts.app>
