<x-layouts.public :title="'Message sent — SAPRF'" :current="'contact'">
    <div class="bg-stone-50">
        <div class="mx-auto max-w-2xl px-4 sm:px-6 py-16 text-center">
            <div class="mx-auto flex size-14 items-center justify-center rounded-full bg-emerald-100">
                <svg class="size-7 text-emerald-700" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" />
                </svg>
            </div>
            <h1 class="mt-6 font-heading text-3xl font-bold text-stone-900 tracking-tight">Thanks — we've got your message</h1>
            <p class="mt-3 text-sm text-stone-600">A SAPRF administrator will review your enquiry and get back to you at the email address you provided. Please allow a few business days for a response.</p>
            <div class="mt-8 flex items-center justify-center gap-3">
                <a href="/" class="rounded-lg bg-stone-900 px-5 py-2.5 text-sm font-semibold text-white hover:bg-stone-800">Home</a>
                <a href="/events" class="rounded-lg border border-stone-300 px-5 py-2.5 text-sm font-semibold text-stone-700 hover:bg-white">See upcoming events</a>
            </div>
        </div>
    </div>
</x-layouts.public>
