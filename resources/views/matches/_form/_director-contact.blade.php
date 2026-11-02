{{--
    Section 5 of 7 — Director & contact.
    MD name + contact + public visibility + WhatsApp invite. The old
    sidebar "Change director" card is now a <details> disclosure at the
    bottom of this section so an Exco member can transfer ownership
    without hopping tabs. Policy gate (`changeDirector`) is unchanged.
--}}
@php
    /** @var \App\Models\MatchEvent|null $match */
    $match = $match ?? null;
    $canChangeDirector = $match && auth()->user()?->can('changeDirector', $match);

    $directorValue = old('match_director', $match?->match_director);
    $directorContactValue = old('match_director_contact', $match?->match_director_contact);
    $showContactPublicly = old('show_md_contact_publicly', $match?->show_md_contact_publicly);
    $whatsappUrlValue = old('whatsapp_invite_url', $match?->whatsapp_invite_url);
@endphp
<section id="director-contact" class="scroll-mt-24 rounded-xl border border-stone-200 bg-white p-5">
    <header>
        <h3 class="text-sm font-semibold text-stone-900">Director &amp; contact</h3>
        <p class="mt-0.5 text-xs text-stone-500">Who runs the match and how shooters reach them.</p>
    </header>

    <div class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2">
        <div>
            <label for="match_director" class="block text-xs font-medium text-stone-600 mb-1">Match director</label>
            <input type="text" name="match_director" id="match_director" value="{{ $directorValue }}"
                   class="block w-full rounded-lg border border-stone-300 text-sm focus:border-emerald-500 focus:ring-emerald-500" />
            @error('match_director') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
        </div>

        <div>
            <label for="match_director_contact" class="block text-xs font-medium text-stone-600 mb-1">MD contact <span class="text-stone-400 font-normal">(optional)</span></label>
            <input type="text" name="match_director_contact" id="match_director_contact" value="{{ $directorContactValue }}"
                   placeholder="Phone or email"
                   class="block w-full rounded-lg border border-stone-300 text-sm focus:border-emerald-500 focus:ring-emerald-500" />
            @error('match_director_contact') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            <label class="mt-2 inline-flex items-center gap-2 text-xs text-stone-600">
                <input type="hidden" name="show_md_contact_publicly" value="0" />
                <input type="checkbox" name="show_md_contact_publicly" value="1" @checked($showContactPublicly)
                       class="rounded border-stone-300 text-emerald-600 focus:ring-emerald-500" />
                Show MD contact publicly on the event page
            </label>
        </div>

        <div class="sm:col-span-2">
            <label for="whatsapp_invite_url" class="block text-xs font-medium text-stone-600 mb-1">WhatsApp group invite <span class="text-stone-400 font-normal">(optional)</span></label>
            <input type="url" name="whatsapp_invite_url" id="whatsapp_invite_url" value="{{ $whatsappUrlValue }}"
                   placeholder="https://chat.whatsapp.com/…"
                   class="block w-full rounded-lg border border-stone-300 text-sm focus:border-emerald-500 focus:ring-emerald-500" />
            <p class="mt-1 text-xs text-stone-400">Shown to confirmed shooters after they no longer need to pay, so they can join for notifications and match books.</p>
            @error('whatsapp_invite_url') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
        </div>
    </div>

    @if($canChangeDirector)
        {{-- Exco-only ownership transfer. Lives inside this section as a
             native <details> so the form field above and the transfer
             action share one scroll context. The inner panel is the
             existing _change-director-panel partial — same markup, same
             POST route, same policy gate as the old sidebar card. --}}
        <details id="change-director"
                 class="mt-4 rounded-lg border border-stone-200 bg-stone-50/60 group"
                 x-data
                 x-init="if (window.location.hash === '#change-director') $el.open = true">
            <summary class="flex cursor-pointer items-center justify-between gap-2 px-4 py-3 text-sm font-semibold text-stone-800">
                <span>
                    Transfer to another director
                    <span class="ml-2 inline-flex rounded-full bg-stone-200 px-2 py-0.5 text-[10px] font-semibold uppercase text-stone-600">Exco</span>
                </span>
                <flux:icon.chevron-down class="size-4 text-stone-400 transition-transform group-open:rotate-180" />
            </summary>
            <div class="border-t border-stone-200 bg-white p-4">
                @include('matches.workspace._change-director-panel')
            </div>
        </details>
    @endif
</section>
