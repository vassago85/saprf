<x-layouts.app>
    <x-slot:title>Backups - SAPRF</x-slot:title>

    <div class="space-y-8">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-sm text-stone-500">
                    <a href="{{ route('dashboard') }}" class="hover:text-stone-700">Developer</a>
                    <span class="mx-1">&rarr;</span>
                    Backups
                </p>
                <h1 class="font-heading text-3xl font-bold text-stone-900">Backups</h1>
            </div>
            <form method="POST" action="{{ route('developer.backups.store') }}">
                @csrf
                <flux:button type="submit" variant="primary" icon="arrow-path">
                    Run Backup Now
                </flux:button>
            </form>
        </div>

        {{-- R2 status banner --}}
        @if(! $r2Configured)
            <div class="rounded-xl border border-amber-200 bg-amber-50 p-6">
                <div class="flex items-start gap-4">
                    <div class="inline-flex items-center justify-center size-10 rounded-lg bg-amber-100 text-amber-700 shrink-0">
                        <svg class="size-5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z" /></svg>
                    </div>
                    <div class="flex-1">
                        <h3 class="font-heading text-lg font-bold text-amber-900">R2 not configured</h3>
                        <p class="text-sm text-amber-800 mt-1">
                            Backups will only be stored locally inside the container. Add these to your <code class="bg-amber-100 px-1.5 py-0.5 rounded text-xs">.env</code> on production and restart the container to enable off-site backups to Cloudflare R2:
                        </p>
                        <pre class="mt-3 bg-amber-100 border border-amber-200 rounded-lg p-3 text-xs text-amber-900 overflow-x-auto"><code>R2_ACCESS_KEY_ID=...
R2_SECRET_ACCESS_KEY=...
R2_BUCKET=saprf-backups
R2_ENDPOINT=https://&lt;account-id&gt;.r2.cloudflarestorage.com</code></pre>
                    </div>
                </div>
            </div>
        @endif

        {{-- Backups per disk --}}
        @foreach($destinations as $destination)
            <div class="rounded-xl border border-stone-200 bg-white shadow-sm overflow-hidden">
                <div class="px-6 py-4 border-b border-stone-200 flex items-center justify-between">
                    <div class="flex items-center gap-3">
                        <h2 class="font-heading text-xl font-bold text-stone-900">
                            {{ $destination['disk'] === 'r2' ? 'Cloudflare R2' : ucfirst($destination['disk']) }}
                        </h2>
                        @if($destination['reachable'])
                            <span class="inline-flex items-center rounded-full px-2 py-0.5 text-[10px] font-semibold bg-emerald-100 text-emerald-700">Reachable</span>
                        @else
                            <span class="inline-flex items-center rounded-full px-2 py-0.5 text-[10px] font-semibold bg-red-100 text-red-700">Unreachable</span>
                        @endif
                    </div>
                    <span class="text-sm text-stone-500">{{ count($destination['backups']) }} {{ Str::plural('backup', count($destination['backups'])) }}</span>
                </div>

                @if(! $destination['reachable'])
                    <div class="p-6">
                        <p class="text-sm text-red-700">{{ $destination['error'] ?? 'Disk not reachable.' }}</p>
                    </div>
                @elseif(empty($destination['backups']))
                    <div class="p-6 text-center">
                        <p class="text-sm text-stone-500">No backups yet. Click <strong>Run Backup Now</strong> to create one.</p>
                    </div>
                @else
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead class="bg-stone-50">
                                <tr>
                                    <th class="px-5 py-3 text-left text-xs font-semibold uppercase tracking-wider text-stone-500">Date</th>
                                    <th class="px-5 py-3 text-left text-xs font-semibold uppercase tracking-wider text-stone-500">File</th>
                                    <th class="px-5 py-3 text-right text-xs font-semibold uppercase tracking-wider text-stone-500">Size</th>
                                    <th class="px-5 py-3 text-right text-xs font-semibold uppercase tracking-wider text-stone-500"></th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-stone-100">
                                @foreach($destination['backups'] as $backup)
                                    <tr class="hover:bg-stone-50">
                                        <td class="px-5 py-3 text-stone-600 whitespace-nowrap font-mono">{{ $backup['date']?->format('Y-m-d H:i') ?? '—' }}</td>
                                        <td class="px-5 py-3 font-medium text-stone-900 break-all">{{ basename($backup['path']) }}</td>
                                        <td class="px-5 py-3 text-right font-mono text-stone-600 whitespace-nowrap">{{ number_format($backup['size'] / 1024 / 1024, 1) }} MB</td>
                                        <td class="px-5 py-3 text-right">
                                            <a href="{{ route('developer.backups.download', ['disk' => $destination['disk'], 'path' => $backup['path']]) }}"
                                               class="text-sm font-medium text-emerald-700 hover:text-emerald-800">
                                                Download
                                            </a>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>
        @endforeach

        {{-- Schedule info --}}
        <div class="rounded-xl border border-stone-200 bg-stone-50 p-6 space-y-2">
            <h2 class="font-heading text-lg font-bold text-stone-900">Schedule</h2>
            <p class="text-sm text-stone-600">Automatic daily backup runs at 02:00 UTC via the Laravel scheduler. Old backups are pruned per <code class="text-xs bg-stone-200 px-1.5 py-0.5 rounded">config/backup.php</code> cleanup defaults (keep all backups for 7 days, daily for 16 days, weekly for 8 weeks, then monthly).</p>
        </div>
    </div>
</x-layouts.app>
