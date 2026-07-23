<x-layouts.app>
    <x-slot:title>Mail Settings - SAPRF</x-slot:title>

    <div class="space-y-8">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-sm text-stone-500">
                    <a href="{{ route('dashboard') }}" class="hover:text-stone-700">Developer</a>
                    <span class="mx-1">&rarr;</span>
                    Mail
                </p>
                <h1 class="font-heading text-3xl font-bold text-stone-900">Mail Settings</h1>
            </div>
            @if($status['configured'])
                <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-semibold bg-emerald-100 text-emerald-800">Configured</span>
            @else
                <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-semibold bg-amber-100 text-amber-800">Not configured</span>
            @endif
        </div>

        {{-- Current Status --}}
        <div class="rounded-xl border border-stone-200 bg-white shadow-sm p-6 space-y-4">
            <h2 class="font-heading text-xl font-bold text-stone-900">Current Status</h2>
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                <div class="rounded-lg bg-stone-50 border border-stone-200 p-4">
                    <p class="text-xs text-stone-500 uppercase tracking-wider">Mailer</p>
                    <p class="text-lg font-bold text-stone-900 mt-1 font-mono">{{ $status['mailer'] ?? '—' }}</p>
                </div>
                <div class="rounded-lg bg-stone-50 border border-stone-200 p-4">
                    <p class="text-xs text-stone-500 uppercase tracking-wider">From Address</p>
                    <p class="text-lg font-bold text-stone-900 mt-1 break-all">{{ $status['from_address'] ?? '—' }}</p>
                </div>
                <div class="rounded-lg bg-stone-50 border border-stone-200 p-4">
                    <p class="text-xs text-stone-500 uppercase tracking-wider">From Name</p>
                    <p class="text-lg font-bold text-stone-900 mt-1">{{ $status['from_name'] ?? '—' }}</p>
                </div>
            </div>

            <form method="POST" action="{{ route('developer.mail.test') }}" class="flex flex-col sm:flex-row sm:items-end gap-3">
                @csrf
                <div class="flex-1 min-w-0">
                    <label for="test_to" class="block text-sm font-medium text-stone-700">Send test to</label>
                    <input type="email" name="to" id="test_to" required
                           value="{{ old('to') }}"
                           placeholder="you@example.com"
                           class="mt-1 block w-full rounded-lg border border-stone-300 px-3 py-2 text-sm text-stone-900 shadow-sm focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500"
                           @disabled(! $status['configured'])>
                    @error('to')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                </div>
                <flux:button type="submit" variant="primary" icon="paper-airplane" :disabled="! $status['configured']">
                    Send test email
                </flux:button>
                @unless($status['configured'])
                    <p class="text-xs text-stone-500 sm:basis-full">Configure Mailgun below before testing.</p>
                @endunless
            </form>
        </div>

        {{-- Edit Form --}}
        <form method="POST" action="{{ route('developer.mail.update') }}" class="rounded-xl border border-stone-200 bg-white shadow-sm p-6 space-y-6">
            @csrf
            @method('PUT')

            <h2 class="font-heading text-xl font-bold text-stone-900">Mailgun Configuration</h2>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-6">
                <div>
                    <label for="mailgun_domain" class="block text-sm font-medium text-stone-700">Mailgun Domain</label>
                    <input type="text" name="mailgun_domain" id="mailgun_domain"
                           value="{{ old('mailgun_domain', $settings['mailgun_domain'] ?? '') }}"
                           placeholder="e.g. mg.saprf.co.za"
                           class="mt-1 block w-full rounded-lg border border-stone-300 px-3 py-2 text-sm text-stone-900 shadow-sm focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500">
                    @error('mailgun_domain')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                </div>

                <div>
                    <label for="mailgun_secret" class="block text-sm font-medium text-stone-700">Mailgun API Key</label>
                    <input type="password" name="mailgun_secret" id="mailgun_secret"
                           value="{{ old('mailgun_secret', $settings['mailgun_secret'] ?? '') }}"
                           placeholder="key-xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx"
                           class="mt-1 block w-full rounded-lg border border-stone-300 px-3 py-2 text-sm text-stone-900 shadow-sm focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500">
                    @error('mailgun_secret')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                </div>

                <div>
                    <label for="mailgun_endpoint" class="block text-sm font-medium text-stone-700">Mailgun Endpoint</label>
                    <select name="mailgun_endpoint" id="mailgun_endpoint"
                            class="mt-1 block w-full rounded-lg border border-stone-300 px-3 py-2 text-sm text-stone-900 shadow-sm focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500">
                        <option value="api.eu.mailgun.net" @selected(old('mailgun_endpoint', $settings['mailgun_endpoint'] ?? 'api.eu.mailgun.net') === 'api.eu.mailgun.net')>EU (api.eu.mailgun.net)</option>
                        <option value="api.mailgun.net" @selected(old('mailgun_endpoint', $settings['mailgun_endpoint'] ?? '') === 'api.mailgun.net')>US (api.mailgun.net)</option>
                    </select>
                    <p class="mt-1 text-xs text-stone-400">EU recommended for South Africa (POPIA).</p>
                </div>

                <div>
                    <label for="mail_from_address" class="block text-sm font-medium text-stone-700">From Address</label>
                    <input type="email" name="mail_from_address" id="mail_from_address"
                           value="{{ old('mail_from_address', $settings['mail_from_address'] ?? '') }}"
                           placeholder="noreply@saprf.co.za"
                           class="mt-1 block w-full rounded-lg border border-stone-300 px-3 py-2 text-sm text-stone-900 shadow-sm focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500">
                    @error('mail_from_address')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                </div>

                <div class="sm:col-span-2">
                    <label for="mail_from_name" class="block text-sm font-medium text-stone-700">From Name</label>
                    <input type="text" name="mail_from_name" id="mail_from_name"
                           value="{{ old('mail_from_name', $settings['mail_from_name'] ?? '') }}"
                           placeholder="SAPRF"
                           class="mt-1 block w-full rounded-lg border border-stone-300 px-3 py-2 text-sm text-stone-900 shadow-sm focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500">
                    @error('mail_from_name')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                </div>
            </div>

            <div class="flex items-center justify-end gap-3 pt-4 border-t border-stone-200">
                <flux:button type="submit" variant="primary" icon="check">
                    Save Settings
                </flux:button>
            </div>
        </form>
    </div>
</x-layouts.app>
