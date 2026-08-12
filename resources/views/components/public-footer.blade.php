<footer class="bg-stone-900 text-stone-400">
    <div class="max-w-7xl mx-auto px-6 py-16">
        <div class="grid sm:grid-cols-2 lg:grid-cols-4 gap-10">
            <div>
                <div class="mb-4">
                    <img src="/saprf-logo-white-text.png" alt="SAPRF" class="h-10 w-auto">
                </div>
                <p class="text-sm leading-relaxed">The official competition platform for PRS and PR22 precision rifle — memberships, match management, scoring, and national standings.</p>
            </div>

            <div>
                <h4 class="text-sm font-semibold text-white uppercase tracking-wider mb-4">Quick Links</h4>
                <ul class="space-y-2 text-sm">
                    <li><a href="/" class="text-stone-300 hover:text-white transition">Home</a></li>
                    <li><a href="{{ route('register') }}" class="text-stone-300 hover:text-white transition">Join SAPRF</a></li>
                    <li><a href="{{ route('login') }}" class="text-stone-300 hover:text-white transition">Login</a></li>
                </ul>
            </div>

            <div>
                <h4 class="text-sm font-semibold text-white uppercase tracking-wider mb-4">Competition</h4>
                <ul class="space-y-2 text-sm">
                    <li><a href="/events" class="text-stone-300 hover:text-white transition">Events</a></li>
                    <li><a href="/standings" class="text-stone-300 hover:text-white transition">Standings</a></li>
                    <li><a href="/events?tab=results" class="text-stone-300 hover:text-white transition">Results</a></li>
                </ul>
            </div>

            <div>
                <h4 class="text-sm font-semibold text-white uppercase tracking-wider mb-4">Contact</h4>
                <ul class="space-y-2 text-sm">
                    <li><a href="{{ route('contact.create') }}" class="text-stone-300 hover:text-white transition">Send us a message</a></li>
                    <li class="text-stone-300">South Africa</li>
                </ul>
            </div>
        </div>

        <div class="mt-12 pt-8 border-t border-stone-800 flex flex-col sm:flex-row items-center justify-between gap-3">
            <p class="text-sm text-stone-500">&copy; {{ date('Y') }} South African Precision Rifle Federation (NPC). All rights reserved.</p>
            <div class="flex items-center gap-4">
                <a href="{{ route('legal.privacy') }}" class="text-sm text-stone-500 hover:text-stone-300 transition">Privacy Policy</a>
                <a href="{{ route('legal.terms') }}" class="text-sm text-stone-500 hover:text-stone-300 transition">Terms &amp; Conditions</a>
            </div>
        </div>
    </div>
</footer>
