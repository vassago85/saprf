<x-layouts.app :title="$match->name . ' — Messages'">
    @include('matches.workspace._chrome')

    <div class="mt-6 grid gap-4 lg:grid-cols-3">
        <div class="rounded-xl border border-stone-200 bg-white p-5 lg:col-span-2">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <h3 class="text-sm font-semibold text-stone-900">Sent bulletins</h3>
                <div class="flex flex-wrap items-center gap-2">
                    @can('update', $match)
                        @if($match->hasWhatsappInvite())
                            <form method="POST" action="{{ route('matches.email-whatsapp-invite', $match) }}">
                                @csrf
                                <button type="submit"
                                        class="inline-flex items-center gap-1.5 rounded-md bg-[#25D366] px-3 py-1.5 text-sm font-semibold text-white hover:bg-[#1da851]"
                                        onclick="return confirm('Email the WhatsApp group link to confirmed entrants? Each person gets one mail with a button to join.');">
                                    Email WhatsApp group link
                                </button>
                            </form>
                        @else
                            <a href="{{ route('matches.settings', $match) }}#whatsapp_invite_url" wire:navigate
                               class="inline-flex items-center gap-1.5 rounded-md border border-stone-300 bg-white px-3 py-1.5 text-sm font-semibold text-stone-700 hover:bg-stone-50">
                                Add WhatsApp group link
                            </a>
                        @endif
                    @endcan
                    <a href="{{ route('matches.announcements.create', $match) }}" wire:navigate
                       class="inline-flex items-center gap-1.5 rounded-md bg-emerald-600 px-3 py-1.5 text-sm font-semibold text-white hover:bg-emerald-700">
                        <flux:icon.paper-airplane class="size-4" />
                        New message
                    </a>
                </div>
            </div>

            @if($announcements->isEmpty())
                <div class="mt-6 rounded-lg border border-dashed border-stone-200 px-4 py-10 text-center text-sm text-stone-500">
                    No bulletins have been sent for this match yet.
                    <div class="mt-3 text-xs">
                        Confirmed + waitlisted entrants will receive your message by email and in the Communications inbox.
                    </div>
                </div>
            @else
                <ul class="mt-4 divide-y divide-stone-100">
                    @foreach($announcements as $ann)
                        <li class="flex flex-wrap items-start justify-between gap-3 py-3">
                            <div class="min-w-0 flex-1">
                                <p class="truncate text-sm font-semibold text-stone-900">{{ $ann->title }}</p>
                                <p class="mt-0.5 text-xs text-stone-500">
                                    {{ ($ann->sent_at ?? $ann->created_at)?->format('d M Y H:i') }}
                                    · {{ $ann->recipient_count ?? 0 }}
                                    {{ \Illuminate\Support\Str::plural('recipient', $ann->recipient_count ?? 0) }}
                                    @if($ann->creator)
                                        · {{ $ann->creator->name }}
                                    @endif
                                </p>
                            </div>
                            <div class="shrink-0 text-xs">
                                <span class="inline-flex rounded-full bg-stone-100 px-2 py-0.5 font-semibold text-stone-600">
                                    {{ $ann->status?->label() ?? 'Draft' }}
                                </span>
                            </div>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>

        <div class="rounded-xl border border-stone-200 bg-white p-5">
            <h3 class="text-sm font-semibold text-stone-900">Who gets a message?</h3>
            <dl class="mt-3 space-y-2 text-sm">
                <div class="flex items-baseline justify-between">
                    <dt class="text-stone-600">All confirmed + waitlisted</dt>
                    <dd class="text-right font-semibold tabular-nums text-stone-900">{{ $counts['recipients'] }}</dd>
                </div>
                <div class="flex items-baseline justify-between">
                    <dt class="text-stone-600">Paid entrants</dt>
                    <dd class="text-right font-semibold tabular-nums text-stone-900">{{ $counts['paid'] }}</dd>
                </div>
                <div class="flex items-baseline justify-between">
                    <dt class="text-stone-600">Pending payment</dt>
                    <dd class="text-right font-semibold tabular-nums text-stone-900">{{ $counts['pending_payment'] }}</dd>
                </div>
                <div class="flex items-baseline justify-between">
                    <dt class="text-stone-600">Waitlist</dt>
                    <dd class="text-right font-semibold tabular-nums text-stone-900">{{ $counts['waitlisted'] }}</dd>
                </div>
            </dl>
            <p class="mt-4 text-xs text-stone-500">
                Messages are sent via email and the Communications inbox. Managed-junior rows deliver to the parent.
                The WhatsApp button emails confirmed entrants who can join the group. Each mail has one button: Join match WhatsApp group.
            </p>
        </div>
    </div>
</x-layouts.app>
