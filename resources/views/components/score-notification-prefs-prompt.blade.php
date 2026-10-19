{{--
    One-time post-login prompt asking the shooter which score-complete
    notifications they want to receive. Visible exactly once per user:
    we stamp `notification_preferences.score_prefs_configured_at` on
    save (or when they tick "no thanks") and never show it again.

    Rendered from `layouts/app` next to the existing `x-push-nudge` and
    `x-outstanding-acknowledgements`. Hidden entirely when:
      - User is not authenticated
      - User has already configured their score preferences
      - Match director notifications: this is for shooters, not MDs,
        but we deliberately still prompt MDs — they shoot other
        matches too, and skipping them would create a silent "why
        aren't I getting score emails?" trap.

    Copy mirrors the Telegram poll language the user approved: own
    matches on by default, PRS and PR22 series feeds are opt-in. The
    closing line always points at the full Notification Preferences
    section so the shooter knows they are not locked in.
--}}
@auth
    @php
        $prefForPrompt = auth()->user()?->notificationPreference;
        $needsPrompt = auth()->user() && ($prefForPrompt?->score_prefs_configured_at === null);
    @endphp

    @if ($needsPrompt)
        <div
            x-data="{ dismissed: false }"
            x-show="!dismissed"
            x-cloak
            class="mb-6 overflow-hidden rounded-xl border border-emerald-200 bg-white shadow-sm"
        >
            <div class="border-b border-emerald-100 bg-emerald-50 px-5 py-3">
                <div class="flex items-start gap-3">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.75" stroke="currentColor" class="size-5 shrink-0 text-emerald-700 mt-0.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9.813 15.904 9 18.75l-.813-2.846a4.5 4.5 0 0 0-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 0 0 3.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 0 0 3.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 0 0-3.09 3.09ZM18.259 8.715 18 9.75l-.259-1.035a3.375 3.375 0 0 0-2.455-2.456L14.25 6l1.036-.259a3.375 3.375 0 0 0 2.455-2.456L18 2.25l.259 1.035a3.375 3.375 0 0 0 2.456 2.456L21.75 6l-1.035.259a3.375 3.375 0 0 0-2.456 2.456Z"/>
                    </svg>
                    <div class="flex-1">
                        <h3 class="font-heading text-base font-semibold text-emerald-900">
                            Want a heads-up when match scores are up?
                        </h3>
                        <p class="mt-0.5 text-sm text-emerald-800/90">
                            Scores are provisional at first and may change while names and entries are checked.
                            Pick what you want to hear about — you can change these any time in your
                            <a href="{{ route('profile') }}#notification-preferences" class="font-semibold underline hover:text-emerald-900">Notification Preferences</a>.
                        </p>
                    </div>
                </div>
            </div>

            <form method="POST" action="{{ route('profile.score-notification-prompt.save') }}" class="px-5 py-4 space-y-3">
                @csrf

                <label class="flex items-start gap-3 rounded-lg border border-stone-200 bg-white p-3 transition hover:border-emerald-300 hover:bg-emerald-50/40 cursor-pointer">
                    <input type="checkbox" name="notify_own_match_scores" value="1" checked
                        class="mt-0.5 size-4 rounded border-stone-300 text-emerald-600 focus:ring-emerald-500">
                    <div>
                        <span class="block text-sm font-semibold text-stone-900">Matches I shot</span>
                        <p class="text-xs text-stone-500 mt-0.5">We'll tell you when the final scores for a match you entered have been checked and are complete.</p>
                    </div>
                </label>

                <label class="flex items-start gap-3 rounded-lg border border-stone-200 bg-white p-3 transition hover:border-emerald-300 hover:bg-emerald-50/40 cursor-pointer">
                    <input type="checkbox" name="notify_all_prs_scores" value="1"
                        class="mt-0.5 size-4 rounded border-stone-300 text-emerald-600 focus:ring-emerald-500">
                    <div>
                        <span class="block text-sm font-semibold text-stone-900">All PRS matches</span>
                        <p class="text-xs text-stone-500 mt-0.5">Follow every PRS match, even the ones you didn't shoot.</p>
                    </div>
                </label>

                <label class="flex items-start gap-3 rounded-lg border border-stone-200 bg-white p-3 transition hover:border-emerald-300 hover:bg-emerald-50/40 cursor-pointer">
                    <input type="checkbox" name="notify_all_pr22_scores" value="1"
                        class="mt-0.5 size-4 rounded border-stone-300 text-emerald-600 focus:ring-emerald-500">
                    <div>
                        <span class="block text-sm font-semibold text-stone-900">All PR22 matches</span>
                        <p class="text-xs text-stone-500 mt-0.5">Follow every PR22 match, even the ones you didn't shoot.</p>
                    </div>
                </label>

                <div class="flex flex-wrap items-center justify-end gap-2 pt-1">
                    <button type="submit"
                        onclick="this.form.querySelectorAll('input[type=checkbox]').forEach(c=>c.checked=false);"
                        class="rounded-md border border-stone-300 bg-white px-3 py-1.5 text-sm font-semibold text-stone-600 hover:bg-stone-50">
                        No thanks
                    </button>
                    <button type="submit"
                        class="rounded-md bg-emerald-700 px-3 py-1.5 text-sm font-semibold text-white shadow-sm hover:bg-emerald-800">
                        Save my choices
                    </button>
                </div>
            </form>
        </div>
    @endif
@endauth
