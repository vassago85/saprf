<x-layouts.app :title="'Enquiry from ' . $message->fullName()">
    <div class="max-w-3xl space-y-6">
        <div>
            <a href="{{ route('contact-messages.index') }}" class="text-sm text-stone-500 hover:text-stone-800">← Back to enquiries</a>
            <div class="mt-2 flex items-center gap-2">
                <h1 class="font-heading text-3xl font-bold text-stone-900 tracking-tight">{{ $message->subject }}</h1>
                @if ($message->spam_status !== \App\Models\ContactMessage::SPAM_CLEAN)
                    <span class="inline-flex items-center rounded-full bg-amber-50 px-2.5 py-0.5 text-xs font-semibold text-amber-800 ring-1 ring-inset ring-amber-200">
                        Caught as spam · {{ $message->spam_status }}
                    </span>
                @endif
            </div>
            <p class="mt-1 text-sm text-stone-500">Received {{ $message->created_at->format('D, d M Y H:i') }}</p>
        </div>

        @if (session('success'))
            <div class="rounded-lg border border-emerald-200 bg-emerald-50 p-3 text-sm text-emerald-800">{{ session('success') }}</div>
        @endif

        <div class="rounded-xl border border-stone-200 bg-white p-6 shadow-sm space-y-4">
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 text-sm">
                <div>
                    <p class="text-xs uppercase tracking-wide text-stone-500">From</p>
                    <p class="mt-0.5 text-stone-900 font-medium">{{ $message->fullName() }}</p>
                </div>
                <div>
                    <p class="text-xs uppercase tracking-wide text-stone-500">Email</p>
                    <p class="mt-0.5 text-stone-900 font-mono text-sm">{{ $message->email }}</p>
                </div>
                <div>
                    <p class="text-xs uppercase tracking-wide text-stone-500">IP · agent</p>
                    <p class="mt-0.5 text-stone-600 text-xs font-mono truncate" title="{{ $message->user_agent }}">{{ $message->ip_address }} · {{ Str::limit($message->user_agent, 40) }}</p>
                </div>
            </div>

            <div>
                <p class="text-xs uppercase tracking-wide text-stone-500 mb-1">Message</p>
                <div class="whitespace-pre-wrap rounded-lg border border-stone-200 bg-stone-50 p-4 text-sm text-stone-800">{{ $message->message }}</div>
            </div>

            <div class="flex items-center gap-3">
                <a href="mailto:{{ $message->email }}?subject={{ urlencode('Re: ' . $message->subject) }}" class="inline-flex items-center gap-2 rounded-lg bg-emerald-700 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-800">
                    Reply by email
                </a>
            </div>
        </div>

        <div class="rounded-xl border border-stone-200 bg-white p-6 shadow-sm">
            <h2 class="text-sm font-semibold uppercase tracking-wide text-stone-500 mb-3">Triage</h2>
            @if ($message->handled_at)
                <p class="text-sm text-stone-700">
                    Marked handled on <strong>{{ $message->handled_at->format('D, d M Y H:i') }}</strong>
                    by <strong>{{ $message->handler?->name ?? 'unknown' }}</strong>.
                </p>
                @if ($message->handled_notes)
                    <div class="mt-3 whitespace-pre-wrap rounded-lg border border-stone-200 bg-stone-50 p-3 text-sm text-stone-700">{{ $message->handled_notes }}</div>
                @endif
                <form method="POST" action="{{ route('contact-messages.reopen', $message) }}" class="mt-4">
                    @csrf
                    <button type="submit" class="rounded-lg border border-stone-300 bg-white px-4 py-2 text-sm font-medium text-stone-700 hover:bg-stone-50">Reopen</button>
                </form>
            @else
                <form method="POST" action="{{ route('contact-messages.mark-handled', $message) }}" class="space-y-3">
                    @csrf
                    <div>
                        <label for="handled_notes" class="block text-sm font-medium text-stone-700 mb-1">Notes (optional)</label>
                        <textarea name="handled_notes" id="handled_notes" rows="3" maxlength="2000" class="w-full rounded-lg border border-stone-300 text-sm py-2 px-3"></textarea>
                    </div>
                    <button type="submit" class="rounded-lg bg-emerald-700 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-800">Mark as handled</button>
                </form>
            @endif
        </div>
    </div>
</x-layouts.app>
