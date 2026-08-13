<x-layouts.app :title="'Contact Enquiries'">
    <div class="space-y-6">
        <div class="flex items-center justify-between">
            <div>
                <h1 class="font-heading text-3xl font-bold text-stone-900 tracking-tight">Contact Enquiries</h1>
                <p class="mt-1 text-sm text-stone-500">Submissions from the public <a href="{{ route('contact.create') }}" class="text-emerald-700 underline">/contact</a> form. Every message is stored here even if outbound mail failed.</p>
            </div>
        </div>

        @if (session('success'))
            <div class="rounded-lg border border-emerald-200 bg-emerald-50 p-3 text-sm text-emerald-800">{{ session('success') }}</div>
        @endif

        <div class="grid grid-cols-2 sm:grid-cols-3 gap-4">
            <div class="rounded-xl border border-stone-200 bg-white p-4">
                <p class="text-xs uppercase tracking-wide text-stone-500">Clean · unhandled</p>
                <p class="mt-1 text-2xl font-bold text-emerald-700">{{ $counts['clean_unhandled'] }}</p>
            </div>
            <div class="rounded-xl border border-stone-200 bg-white p-4">
                <p class="text-xs uppercase tracking-wide text-stone-500">Caught as spam</p>
                <p class="mt-1 text-2xl font-bold text-amber-600">{{ $counts['spam'] }}</p>
            </div>
        </div>

        <form method="GET" action="{{ route('contact-messages.index') }}" class="rounded-xl border border-stone-200 bg-white p-4 shadow-sm flex flex-wrap gap-3 items-end">
            <div>
                <label for="status" class="block text-xs font-medium text-stone-500 mb-1">Spam status</label>
                <select name="status" id="status" class="rounded-lg border border-stone-300 text-sm py-2 px-3">
                    <option value="">All</option>
                    <option value="clean" @selected($filters['status'] === 'clean')>Clean</option>
                    <option value="honeypot" @selected($filters['status'] === 'honeypot')>Honeypot hit</option>
                    <option value="too_fast" @selected($filters['status'] === 'too_fast')>Submitted too fast</option>
                </select>
            </div>
            <div>
                <label for="handled" class="block text-xs font-medium text-stone-500 mb-1">Handled</label>
                <select name="handled" id="handled" class="rounded-lg border border-stone-300 text-sm py-2 px-3">
                    <option value="">All</option>
                    <option value="unhandled" @selected($filters['handled'] === 'unhandled')>Unhandled</option>
                    <option value="handled" @selected($filters['handled'] === 'handled')>Handled</option>
                </select>
            </div>
            <button type="submit" class="rounded-lg bg-stone-900 px-4 py-2 text-sm font-medium text-white hover:bg-stone-800">Filter</button>
        </form>

        <div class="rounded-xl border border-stone-200 bg-white shadow-sm overflow-hidden">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-stone-200">
                <thead class="bg-stone-50">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-stone-500">When</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-stone-500">From</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-stone-500">Subject</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-stone-500">Status</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-stone-500">Handled</th>
                        <th class="px-4 py-3"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-stone-100">
                    @forelse ($messages as $m)
                        <tr class="{{ $m->isSpam() ? 'bg-amber-50/40' : '' }}">
                            <td class="px-4 py-3 text-sm text-stone-600 whitespace-nowrap">{{ $m->created_at->format('Y-m-d H:i') }}</td>
                            <td class="px-4 py-3 text-sm">
                                <div class="font-semibold text-stone-900">{{ $m->fullName() }}</div>
                                <div class="text-xs text-stone-500">{{ $m->email }}</div>
                            </td>
                            <td class="px-4 py-3 text-sm text-stone-700">{{ Str::limit($m->subject, 60) }}</td>
                            <td class="px-4 py-3 text-sm">
                                @if ($m->spam_status === \App\Models\ContactMessage::SPAM_CLEAN)
                                    <span class="inline-flex items-center rounded-full bg-emerald-50 px-2.5 py-0.5 text-xs font-semibold text-emerald-800 ring-1 ring-inset ring-emerald-200">Clean</span>
                                @elseif ($m->spam_status === \App\Models\ContactMessage::SPAM_HONEYPOT)
                                    <span class="inline-flex items-center rounded-full bg-amber-50 px-2.5 py-0.5 text-xs font-semibold text-amber-800 ring-1 ring-inset ring-amber-200">Honeypot</span>
                                @else
                                    <span class="inline-flex items-center rounded-full bg-amber-50 px-2.5 py-0.5 text-xs font-semibold text-amber-800 ring-1 ring-inset ring-amber-200">Too fast</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-sm text-stone-600">
                                @if ($m->handled_at)
                                    <span class="text-emerald-700 font-medium">{{ $m->handled_at->format('Y-m-d') }}</span>
                                    <div class="text-xs text-stone-400">by {{ $m->handler?->name ?? '—' }}</div>
                                @else
                                    <span class="text-stone-400">Open</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-right text-sm">
                                <a href="{{ route('contact-messages.show', $m) }}" class="text-emerald-700 font-medium hover:text-emerald-900">Open</a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="px-4 py-10 text-center text-sm text-stone-400">No enquiries match your filters.</td>
                        </tr>
                    @endforelse
                </tbody>
                </table>
            </div>
        </div>

        <div>{{ $messages->links() }}</div>
    </div>
</x-layouts.app>
