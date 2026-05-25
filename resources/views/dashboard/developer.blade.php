<x-layouts.app>
    <x-slot:title>Developer Dashboard - SAPRF</x-slot:title>

    <div class="space-y-8">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-sm text-stone-500">Welcome back, {{ Str::before($user->name, ' ') }}</p>
                <h1 class="font-heading text-3xl font-bold text-stone-900">Developer</h1>
            </div>
            <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-semibold bg-violet-100 text-violet-800">Developer</span>
        </div>

        <hr class="border-stone-200 my-6">

        {{-- Environment --}}
        <div class="rounded-xl border border-stone-200 bg-white shadow-sm p-6 space-y-4">
            <h2 class="font-heading text-xl font-bold text-stone-900">Environment</h2>
            <div class="grid grid-cols-2 sm:grid-cols-4 gap-4">
                <div class="rounded-lg bg-stone-50 border border-stone-200 p-4">
                    <p class="text-xs text-stone-500 uppercase tracking-wider">App Env</p>
                    <p class="text-lg font-bold mt-1 {{ $appEnv === 'production' ? 'text-emerald-700' : 'text-amber-600' }}">{{ $appEnv }}</p>
                </div>
                <div class="rounded-lg bg-stone-50 border border-stone-200 p-4">
                    <p class="text-xs text-stone-500 uppercase tracking-wider">Debug</p>
                    <p class="text-lg font-bold mt-1 {{ $appDebug ? 'text-red-600' : 'text-stone-900' }}">{{ $appDebug ? 'On' : 'Off' }}</p>
                </div>
                <div class="rounded-lg bg-stone-50 border border-stone-200 p-4">
                    <p class="text-xs text-stone-500 uppercase tracking-wider">PHP</p>
                    <p class="text-lg font-bold text-stone-900 mt-1 font-mono">{{ $phpVersion }}</p>
                </div>
                <div class="rounded-lg bg-stone-50 border border-stone-200 p-4">
                    <p class="text-xs text-stone-500 uppercase tracking-wider">Laravel</p>
                    <p class="text-lg font-bold text-stone-900 mt-1 font-mono">{{ $laravelVersion }}</p>
                </div>
            </div>
        </div>

        {{-- Coming Soon: Backups + Mail --}}
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
            <div class="rounded-xl border border-dashed border-stone-300 bg-stone-50 p-6 space-y-3">
                <div class="flex items-center gap-3">
                    <div class="inline-flex items-center justify-center size-10 rounded-lg bg-stone-200 text-stone-600">
                        <svg class="size-5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 12.75V12A2.25 2.25 0 0 1 4.5 9.75h15A2.25 2.25 0 0 1 21.75 12v.75m-8.69-6.44-2.12-2.12a1.5 1.5 0 0 0-1.061-.44H4.5A2.25 2.25 0 0 0 2.25 6v12a2.25 2.25 0 0 0 2.25 2.25h15A2.25 2.25 0 0 0 21.75 18V9a2.25 2.25 0 0 0-2.25-2.25h-5.379a1.5 1.5 0 0 1-1.06-.44Z" /></svg>
                    </div>
                    <h3 class="font-heading text-lg font-bold text-stone-900">R2 Backups</h3>
                    <span class="inline-flex items-center rounded-full px-2 py-0.5 text-[10px] font-semibold bg-stone-200 text-stone-600 ml-auto">Coming soon</span>
                </div>
                <p class="text-sm text-stone-600">Scheduled database and file backups to Cloudflare R2 storage. Restore from snapshot.</p>
            </div>

            <div class="rounded-xl border border-dashed border-stone-300 bg-stone-50 p-6 space-y-3">
                <div class="flex items-center gap-3">
                    <div class="inline-flex items-center justify-center size-10 rounded-lg bg-stone-200 text-stone-600">
                        <svg class="size-5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M21.75 6.75v10.5a2.25 2.25 0 0 1-2.25 2.25h-15a2.25 2.25 0 0 1-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0 0 19.5 4.5h-15a2.25 2.25 0 0 0-2.25 2.25m19.5 0v.243a2.25 2.25 0 0 1-1.07 1.916l-7.5 4.615a2.25 2.25 0 0 1-2.36 0L3.32 8.91a2.25 2.25 0 0 1-1.07-1.916V6.75" /></svg>
                    </div>
                    <h3 class="font-heading text-lg font-bold text-stone-900">Mail Settings</h3>
                    <span class="inline-flex items-center rounded-full px-2 py-0.5 text-[10px] font-semibold bg-stone-200 text-stone-600 ml-auto">Coming soon</span>
                </div>
                <p class="text-sm text-stone-600">SMTP / Mailgun configuration, test mail, and recent send log.</p>
            </div>
        </div>

        <hr class="border-stone-200 my-6">

        {{-- Quick Links to existing admin tools --}}
        <div>
            <h2 class="font-heading text-xl font-bold text-stone-900 mb-4">Admin Tools</h2>
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3">
                <flux:button href="{{ route('site-settings.index') }}" variant="filled" class="justify-start" icon="adjustments-horizontal">
                    Site Settings
                </flux:button>
                <flux:button href="{{ route('user-management.index') }}" variant="filled" class="justify-start" icon="user-group">
                    User Management
                </flux:button>
                <flux:button href="{{ route('audit-logs.index') }}" variant="filled" class="justify-start" icon="document-magnifying-glass">
                    Audit Logs
                </flux:button>
                <flux:button href="{{ route('qualification-rules.index') }}" variant="filled" class="justify-start" icon="cog-6-tooth">
                    Qualification Rules
                </flux:button>
                <flux:button href="{{ route('divisions.index') }}" variant="filled" class="justify-start" icon="squares-2x2">
                    Divisions
                </flux:button>
                <flux:button href="{{ route('categories.index') }}" variant="filled" class="justify-start" icon="rectangle-stack">
                    Categories
                </flux:button>
            </div>
        </div>
    </div>

    <x-dev-switcher />
</x-layouts.app>
