<x-error-page
    status="403"
    title="Members only"
    message="This feature is reserved for paid-up SAPRF members. Match registration stays open to everyone — only certificates, stats, and firearm tracking need an active membership.">
    <div class="flex flex-col sm:flex-row gap-3 justify-center">
        <a href="{{ route('my-membership') }}"
           class="px-5 py-2.5 rounded-xl bg-emerald-700 text-white text-sm font-semibold hover:bg-emerald-800 transition">
            Join or renew membership
        </a>
        <a href="{{ route('events.index') }}"
           class="px-5 py-2.5 rounded-xl bg-stone-100 text-stone-700 text-sm font-semibold hover:bg-stone-200 transition">
            Browse matches
        </a>
    </div>
</x-error-page>
