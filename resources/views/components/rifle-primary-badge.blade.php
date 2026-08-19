@props([
    'rifle',
    'size' => 'sm',
])

@if ($rifle->primarySeriesLabel())
    <span @class([
        'inline-flex items-center rounded-full font-semibold uppercase ring-1 ring-inset shrink-0',
        'px-2 py-0.5 text-[10px]' => $size === 'sm',
        'px-2.5 py-0.5 text-xs' => $size === 'md',
        $rifle->primarySeriesBadgeClasses(),
    ])>{{ $rifle->primarySeriesLabel() }}</span>
@endif
