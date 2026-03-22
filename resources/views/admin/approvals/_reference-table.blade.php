<div class="rounded-xl border border-stone-200 bg-white shadow-sm overflow-hidden">
    <div class="border-b border-stone-200 bg-stone-50 px-6 py-4">
        <h2 class="font-heading text-lg font-bold text-stone-900">
            {{ $title }}
            <span class="ml-2 inline-flex items-center rounded-full px-2 py-0.5 text-xs font-semibold bg-amber-100 text-amber-800">{{ $items->count() }}</span>
        </h2>
    </div>
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead>
                <tr class="border-b border-stone-200">
                    @if(isset($parentColumn))
                        <th class="px-6 py-3 text-left text-xs font-semibold text-stone-500 uppercase tracking-wider">{{ $parentLabel }}</th>
                    @endif
                    @foreach($columns as $key => $label)
                        <th class="px-6 py-3 text-left text-xs font-semibold text-stone-500 uppercase tracking-wider">{{ $label }}</th>
                    @endforeach
                    <th class="px-6 py-3 text-left text-xs font-semibold text-stone-500 uppercase tracking-wider">Date</th>
                    <th class="px-6 py-3 text-right text-xs font-semibold text-stone-500 uppercase tracking-wider">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-stone-100">
                @foreach($items as $item)
                    <tr>
                        @if(isset($parentColumn))
                            <td class="px-6 py-4 text-stone-500">{{ data_get($item, $parentColumn) ?? '—' }}</td>
                        @endif
                        @foreach($columns as $key => $label)
                            <td class="px-6 py-4 {{ $loop->first ? 'font-medium text-stone-900' : 'text-stone-500' }}">{{ $item->{$key} ?? '—' }}</td>
                        @endforeach
                        <td class="px-6 py-4 text-stone-400">{{ $item->created_at->format('d M Y') }}</td>
                        <td class="px-6 py-4 text-right">
                            <div class="flex items-center justify-end gap-2">
                                <form method="POST" action="{{ route('approvals.approve', ['type' => $type, 'id' => $item->id]) }}">
                                    @csrf
                                    <button type="submit" class="inline-flex items-center rounded-lg bg-emerald-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-emerald-700 transition">Approve</button>
                                </form>
                                <form method="POST" action="{{ route('approvals.reject', ['type' => $type, 'id' => $item->id]) }}" onsubmit="return confirm('Remove this item?')">
                                    @csrf
                                    <button type="submit" class="inline-flex items-center rounded-lg bg-red-50 border border-red-200 px-3 py-1.5 text-xs font-semibold text-red-700 hover:bg-red-100 transition">Reject</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>
