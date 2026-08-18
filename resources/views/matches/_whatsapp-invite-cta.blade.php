@props([
    'url',
    'showWhenCompleted' => false,
])

@if($url)
    <a href="{{ $url }}"
       target="_blank"
       rel="noopener noreferrer"
       @if($showWhenCompleted) x-show="completed" x-cloak @endif
       class="inline-flex items-center justify-center gap-2 px-5 py-2.5 rounded-xl bg-[#25D366] text-white text-sm font-semibold hover:bg-[#1da851] transition">
        <svg class="size-4" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
            <path d="M12.04 2C6.58 2 2.15 6.4 2.15 11.83c0 1.74.46 3.44 1.34 4.94L2 22l5.38-1.41a10.1 10.1 0 0 0 4.66 1.13h.01c5.46 0 9.89-4.4 9.89-9.83C21.94 6.4 17.5 2 12.04 2Zm5.76 13.99c-.24.67-1.4 1.23-1.95 1.31-.5.07-1.13.1-1.83-.12-.42-.13-.96-.31-1.66-.61-2.92-1.26-4.82-4.2-4.97-4.4-.14-.19-1.18-1.57-1.18-3 0-1.42.74-2.12 1.01-2.41.26-.29.58-.36.77-.36h.55c.18 0 .42-.04.66.5.24.56.82 1.94.89 2.08.07.14.12.31.02.5-.1.19-.14.31-.29.48-.14.17-.3.38-.43.51-.14.14-.29.29-.12.56.16.27.73 1.2 1.56 1.95 1.08.96 1.98 1.26 2.26 1.4.28.14.44.12.61-.07.16-.19.7-.81.89-1.09.19-.28.38-.23.64-.14.26.1 1.66.78 1.95.92.28.14.47.21.54.33.07.12.07.69-.17 1.36Z"/>
        </svg>
        Join match WhatsApp group
    </a>
@endif
