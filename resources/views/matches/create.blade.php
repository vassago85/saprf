<x-layouts.app :title="'Create match'">
    {{-- Create is deliberately minimal: four sections only. The MD lands
         on Settings right after save with a flash prompting them to fill
         in fees, contact details and the public page before publishing.
         Status is always Draft on save — the stage bar on Settings owns
         publishing from that point on. --}}
    <div class="max-w-5xl">
        <div class="mb-6">
            <h1 class="font-heading text-3xl font-bold tracking-tight text-stone-900">Create match</h1>
            <p class="mt-1 text-sm text-stone-500">
                Only the essentials to start. You'll land on Settings next to add fees, contact details, and the public page before you publish.
            </p>
        </div>

        <form method="POST" action="{{ route('matches.store') }}" class="space-y-4">
            @csrf

            @include('matches._form._basics')
            @include('matches._form._when-where')
            @include('matches._form._entries')

            {{-- Create-mode fees: entry fee + junior fee only. All the
                 rest (club entry, Exco overrides) lives on Settings. --}}
            @include('matches._form._fees', ['createMode' => true])

            @if ($errors->any())
                <div class="rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-700">
                    <ul class="list-inside list-disc space-y-1">
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <div class="flex items-center gap-3">
                <flux:button type="submit" variant="primary">Create match</flux:button>
                <flux:button href="{{ route('matches.index') }}" variant="ghost">Cancel</flux:button>
            </div>
        </form>
    </div>
</x-layouts.app>
