{{--
    Small amber "Locked" chip used next to fields that paid entries make
    read-only on the back-end. The page used to render a single banner at
    the top of the settings form explaining the lock; we now show the
    status inline on each affected field so a scrolled-down admin still
    knows why Save won't let them change the fee or date.

    Usage: @include('matches._form._locked-badge', ['paidCount' => $counts['paid']])
--}}
@php
    $paidCount = $paidCount ?? 0;
    $tooltip = $paidCount > 0
        ? "{$paidCount} paid " . \Illuminate\Support\Str::plural('entry', $paidCount) . " already settled. Changing this value would misalign payouts with what shooters paid. New sign-ups use whatever value is saved; existing entries keep their original price."
        : 'Locked because paid entries exist.';
@endphp
<span class="ml-1 inline-flex items-center gap-1 rounded-full bg-amber-100 px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-amber-800"
      title="{{ $tooltip }}">
    <flux:icon.lock-closed class="size-3" />
    Locked
</span>
