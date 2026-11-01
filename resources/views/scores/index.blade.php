<x-layouts.app :title="'Scores'">
    <x-page-header title="Scores" />

    {{-- Phase 5: the entire filtering / bulk-action table is now a Livewire
         island so filters update inline and the controller stays dumb. --}}
    <livewire:admin.scores-table :match-director-only="$matchDirectorOnly" />
</x-layouts.app>
