@props(['item'])

<flux:navlist.item :icon="$item['icon']" :href="$item['href']" :current="$item['current']">
    {{ $item['label'] }}
    @if($item['badge'] !== null)
        <flux:badge size="sm" :color="$item['badge_color']" class="ml-auto">{{ $item['badge'] }}</flux:badge>
    @endif
</flux:navlist.item>
