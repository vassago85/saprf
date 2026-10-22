{{--
    Cloudflare Turnstile widget.

    Props:
      action             : analytics label — "contact", "register",
                           "login", "forgot-password" (does not affect
                           the challenge itself)
      livewire-property  : when set, the component runs in Livewire mode
                           and pushes the token into the given Livewire
                           property via Livewire.find(id).set(). When
                           omitted, Turnstile injects its own hidden
                           input named "cf-turnstile-response" for plain
                           <form> POSTs.

    IMPORTANT: do NOT put x-dash-prefixed example tags in this file's
    comments. Blade's component compiler scans anonymous component
    templates for <x-...> tags regardless of whether they appear inside
    PHP docblock comments or @props arrays, and the parsed stray tags
    emit orphan @endif's into the parent compiled view.
--}}
@props([
    'action' => 'generic',
    'livewireProperty' => null,
])

@php
    // Local alias so the rest of the template reads cleanly.
    $wireModel = $livewireProperty;
    $turnstile = app(\App\Services\TurnstileService::class);
    $enabled = $turnstile->isEnabled();
    $siteKey = $turnstile->siteKey();
    // Give every widget on a page its own callback name so a page with two
    // Turnstile widgets (unlikely but possible) doesn't collide.
    $cbName = 'saprfTurnstileCb_' . substr(sha1($action . '|' . ($wireModel ?? '')), 0, 12);
@endphp


@if ($enabled)
    <div class="my-4" data-turnstile-wrapper>
        <div class="cf-turnstile"
             data-sitekey="{{ $siteKey }}"
             data-action="{{ $action }}"
             data-size="normal"
             data-callback="{{ $wireModel ? $cbName : '' }}"
        ></div>

        @if ($wireModel)
            {{-- Hidden input mirrors the token into the Livewire payload the
                 next time the component round-trips. The data-callback above
                 also calls Livewire.find(id).set() so the server sees the
                 token on the same user click, no second round-trip. --}}
            <input type="hidden" wire:model="{{ $wireModel }}" data-turnstile-mirror>

            <script>
                window.{{ $cbName }} = function(token) {
                    const wrappers = document.querySelectorAll('[data-turnstile-wrapper]');
                    wrappers.forEach(function (wrapper) {
                        const cbAttr = wrapper.querySelector('.cf-turnstile')?.getAttribute('data-callback');
                        if (cbAttr !== @js($cbName)) return;
                        const mirror = wrapper.querySelector('[data-turnstile-mirror]');
                        const root = wrapper.closest('[wire\\:id]');
                        if (mirror) mirror.value = token;
                        if (root && window.Livewire) {
                            const id = root.getAttribute('wire:id');
                            const component = window.Livewire.find(id);
                            if (component) component.set(@js($wireModel), token);
                        }
                    });
                };
            </script>

            @error($wireModel)
                <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
            @enderror
        @endif
    </div>

    <script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer data-saprf-turnstile-loader></script>
@endif
