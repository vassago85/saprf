<x-layouts.public :title="'Contact — SAPRF'" description="Contact the South African Precision Rifle Federation with membership, match, or general enquiries." :current="'contact'">
    <div class="bg-stone-50">
        <div class="mx-auto max-w-4xl px-4 sm:px-6 py-10 sm:py-14">
            <div class="rounded-xl border border-stone-200 bg-stone-100 p-6 sm:p-8 shadow-sm">
                <h1 class="font-heading text-2xl sm:text-3xl font-bold uppercase tracking-tight text-emerald-800">Contact Us</h1>
                <p class="mt-3 text-sm text-stone-600">If you have any queries, or comments, please complete the below form and we'll get back to you shortly.</p>
                <p class="text-sm text-stone-600">All fields are required to be completed.</p>

                @if (session('error'))
                    <div class="mt-4 rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-800">{{ session('error') }}</div>
                @endif

                @if ($errors->any())
                    <div class="mt-4 rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-800">
                        <p class="font-medium mb-1">Please fix the following:</p>
                        <ul class="list-disc list-inside space-y-0.5">
                            @foreach ($errors->all() as $error)
                                <li>{{ $error }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                <form method="POST" action="{{ route('contact.store') }}" class="mt-6 space-y-4" autocomplete="on">
                    @csrf

                    {{-- Honeypot: hidden with CSS + aria-hidden + tabindex=-1 so
                         real users never see or focus it. Bots that autofill
                         every input trip the trap. --}}
                    <div aria-hidden="true" style="position:absolute;left:-10000px;top:auto;width:1px;height:1px;overflow:hidden;">
                        <label for="hp_field">Do not fill this in.</label>
                        <input type="text" name="hp_field" id="hp_field" tabindex="-1" autocomplete="off" value="">
                    </div>
                    <input type="hidden" name="hp_started_at" value="{{ $started_at }}">

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <div>
                            <label for="first_name" class="sr-only">First name</label>
                            <input type="text" name="first_name" id="first_name" required maxlength="100"
                                   value="{{ old('first_name') }}" placeholder="First name"
                                   autocomplete="given-name"
                                   class="w-full rounded-md border border-stone-300 bg-white text-sm py-2 px-3 focus:ring-emerald-500 focus:border-emerald-500 placeholder-stone-400">
                        </div>
                        <div>
                            <label for="surname" class="sr-only">Surname</label>
                            <input type="text" name="surname" id="surname" required maxlength="100"
                                   value="{{ old('surname') }}" placeholder="Surname"
                                   autocomplete="family-name"
                                   class="w-full rounded-md border border-stone-300 bg-white text-sm py-2 px-3 focus:ring-emerald-500 focus:border-emerald-500 placeholder-stone-400">
                        </div>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <div>
                            <label for="email" class="sr-only">E-mail</label>
                            <input type="email" name="email" id="email" required maxlength="255"
                                   value="{{ old('email') }}" placeholder="E-mail"
                                   autocomplete="email"
                                   class="w-full rounded-md border border-stone-300 bg-white text-sm py-2 px-3 focus:ring-emerald-500 focus:border-emerald-500 placeholder-stone-400">
                        </div>
                        <div>
                            <label for="email_confirmation" class="sr-only">Confirm E-mail</label>
                            <input type="email" name="email_confirmation" id="email_confirmation" required maxlength="255"
                                   value="{{ old('email_confirmation') }}" placeholder="Confirm E-mail"
                                   autocomplete="email"
                                   class="w-full rounded-md border border-stone-300 bg-white text-sm py-2 px-3 focus:ring-emerald-500 focus:border-emerald-500 placeholder-stone-400">
                        </div>
                    </div>

                    <div>
                        <label for="subject" class="sr-only">Subject</label>
                        <input type="text" name="subject" id="subject" required maxlength="255"
                               value="{{ old('subject') }}" placeholder="Subject"
                               class="w-full rounded-md border border-stone-300 bg-white text-sm py-2 px-3 focus:ring-emerald-500 focus:border-emerald-500 placeholder-stone-400">
                    </div>

                    <div>
                        <label for="message" class="sr-only">Message</label>
                        <textarea name="message" id="message" required rows="7" minlength="10" maxlength="5000"
                                  placeholder="Message"
                                  class="w-full rounded-md border border-stone-300 bg-white text-sm py-2 px-3 focus:ring-emerald-500 focus:border-emerald-500 placeholder-stone-400 resize-y">{{ old('message') }}</textarea>
                    </div>

                    <div>
                        <button type="submit" class="inline-flex items-center rounded-md bg-stone-900 px-5 py-2 text-sm font-semibold text-white hover:bg-stone-800 transition">
                            Submit
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</x-layouts.public>
