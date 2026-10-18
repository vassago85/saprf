<?php

use App\Jobs\ProcessMediaItem;
use App\Models\MediaAlbum;
use App\Models\MediaItem;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Validate;
use Livewire\Volt\Component;
use Livewire\WithFileUploads;

new #[Layout('components.layouts.app')] #[Title('Album photos — SAPRF')] class extends Component {
    use WithFileUploads;

    public MediaAlbum $album;

    /** @var array<\Livewire\Features\SupportFileUploads\TemporaryUploadedFile> */
    #[Validate(['uploads.*' => ['image', 'max:51200']])]
    public array $uploads = [];

    public ?int $editingItemId = null;

    public string $caption = '';
    public string $credit = '';
    public string $alt = '';

    public ?int $featuringItemId = null;
    public string $home_label = 'Team South Africa';
    public string $home_headline = '';
    public string $home_summary = '';
    public string $home_link_type = 'sa_team';
    public string $home_link_url = '';

    public function mount(MediaAlbum $album): void
    {
        $this->album = $album->load(['items.album', 'cover']);
    }

    public function uploadBatch(): void
    {
        $this->validate(['uploads.*' => ['image', 'max:51200']]);

        $disk = config('media.disk', 'public');

        foreach ($this->uploads as $file) {
            $filename = Str::uuid()->toString().'.'.$file->getClientOriginalExtension();
            $path = $file->storeAs('media/'.$this->album->id, $filename, $disk);

            $item = MediaItem::create([
                'media_album_id' => $this->album->id,
                'disk' => $disk,
                'path' => $path,
                'alt' => '',   // required by UX, filled in via the modal
                'uploaded_by' => auth()->id(),
                'sort_order' => (int) MediaItem::where('media_album_id', $this->album->id)->max('sort_order') + 10,
            ]);

            // If the album has no cover yet, promote the first upload.
            if (! $this->album->cover_media_item_id) {
                $this->album->update(['cover_media_item_id' => $item->id]);
            }

            Bus::dispatch(new ProcessMediaItem($item));
        }

        $this->uploads = [];
        $this->album = $this->album->fresh(['items.album', 'cover']);
        $this->dispatch('notify', body: 'Uploaded. Variants are generating in the background.');
    }

    public function editItem(int $id): void
    {
        $item = $this->album->items->firstWhere('id', $id);
        if (! $item) return;

        $this->editingItemId = $id;
        $this->caption = (string) $item->caption;
        $this->credit = (string) $item->credit;
        $this->alt = (string) $item->alt;
    }

    public function saveItem(): void
    {
        $this->validate([
            'alt' => ['required', 'string', 'max:300'],
            'caption' => ['nullable', 'string', 'max:500'],
            'credit' => ['nullable', 'string', 'max:200'],
        ], attributes: ['alt' => 'alt text']);

        $item = MediaItem::findOrFail($this->editingItemId);
        $item->update([
            'alt' => $this->alt,
            'caption' => $this->caption ?: null,
            'credit' => $this->credit ?: null,
        ]);

        $this->editingItemId = null;
        $this->album = $this->album->fresh(['items.album', 'cover']);
        $this->dispatch('notify', body: 'Item saved.');
    }

    public function setCover(int $id): void
    {
        $this->album->update(['cover_media_item_id' => $id]);
        $this->album = $this->album->fresh(['items.album', 'cover']);
        $this->dispatch('notify', body: 'Album cover updated.');
    }

    public function deleteItem(int $id): void
    {
        $item = MediaItem::findOrFail($id);
        if ($this->album->cover_media_item_id === $item->id) {
            $this->album->update(['cover_media_item_id' => null]);
        }
        // Note: physical files on disk are left in place for now — they
        // can be swept by a storage GC later. Deleting the row is enough
        // to remove the photo from every public surface.
        $item->delete();

        $this->album = $this->album->fresh(['items.album', 'cover']);
        $this->dispatch('notify', body: 'Item deleted.');
    }

    public function reorder(array $orderedIds): void
    {
        foreach ($orderedIds as $i => $id) {
            MediaItem::where('id', $id)
                ->where('media_album_id', $this->album->id)
                ->update(['sort_order' => ($i + 1) * 10]);
        }

        $this->album = $this->album->fresh(['items.album', 'cover']);
    }

    public function openFeature(int $id): void
    {
        $item = $this->album->items->firstWhere('id', $id);
        if (! $item) return;

        $this->featuringItemId = $id;
        $this->home_label = $item->home_label ?: 'Team South Africa';
        $this->home_headline = $item->home_headline
            ?: ($this->album->national_team_id
                ? 'Flying the flag at the '.$this->album->title
                : $this->album->title);
        $this->home_summary = (string) $item->home_summary;
        $this->home_link_type = $item->home_link_type ?: 'sa_team';
        $this->home_link_url = (string) $item->home_link_url;
    }

    public function saveFeature(): void
    {
        $this->validate([
            'home_label' => ['required', 'string', 'max:60'],
            'home_headline' => ['required', 'string', 'max:200'],
            'home_summary' => ['nullable', 'string', 'max:500'],
            'home_link_type' => ['required', Rule::in(array_keys(MediaItem::LINK_TYPES))],
            'home_link_url' => ['nullable', 'string', 'max:400'],
        ]);

        $item = MediaItem::findOrFail($this->featuringItemId);
        $item->fill([
            'home_label' => $this->home_label,
            'home_headline' => $this->home_headline,
            'home_summary' => $this->home_summary ?: null,
            'home_link_type' => $this->home_link_type,
            'home_link_url' => $this->home_link_url ?: null,
        ])->save();

        // Promote this item (clears is_home_feature on all others atomically).
        $item->featureOnHomepage();

        $this->featuringItemId = null;
        $this->album = $this->album->fresh(['items.album', 'cover']);
        $this->dispatch('notify', body: 'Featured on homepage.');
    }

    public function unfeature(int $id): void
    {
        MediaItem::where('id', $id)->update(['is_home_feature' => false]);
        \Illuminate\Support\Facades\Cache::forget(MediaItem::HOME_FEATURE_CACHE_KEY);

        $this->album = $this->album->fresh(['items.album', 'cover']);
        $this->dispatch('notify', body: 'Removed from homepage.');
    }
};
?>

<div class="max-w-7xl mx-auto px-4 sm:px-6 py-8"
     x-data="{ notice: '' }"
     x-on:notify.window="notice = $event.detail.body; setTimeout(() => notice = '', 3000)">

    <a href="{{ route('media.albums.index') }}" class="text-sm text-stone-500 hover:text-emerald-700 inline-flex items-center gap-1">
        <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5 3 12m0 0 7.5-7.5M3 12h18"/></svg>
        All albums
    </a>

    <div class="mt-3 flex flex-wrap items-end justify-between gap-4">
        <div>
            <div class="text-[11px] font-bold uppercase tracking-wider text-emerald-700">{{ $album->categoryLabel() }}</div>
            <h1 class="font-heading font-extrabold text-3xl sm:text-4xl text-stone-900 mt-1">{{ $album->title }}</h1>
            <p class="text-sm text-stone-500 mt-1">{{ $album->items->count() }} photos · {{ $album->is_published ? 'Published' : 'Draft' }}</p>
        </div>
        <div class="flex gap-2">
            <a href="{{ route('media.albums.edit', ['album' => $album->slug]) }}"
               class="inline-flex items-center min-h-[44px] px-5 rounded-lg border border-stone-300 text-stone-700 text-sm font-semibold hover:bg-stone-50">
                Edit album details
            </a>
            <a href="{{ route('media.show', ['album' => $album->slug]) }}" target="_blank"
               class="inline-flex items-center min-h-[44px] px-5 rounded-lg bg-emerald-700 text-white text-sm font-semibold hover:bg-emerald-800">
                View public page ↗
            </a>
        </div>
    </div>

    <div x-show="notice" x-cloak x-transition
         class="fixed top-6 right-6 z-50 rounded-lg bg-emerald-700 text-white text-sm font-semibold px-4 py-3 shadow-lg"
         x-text="notice"></div>

    {{-- Upload --}}
    <div class="mt-8 bg-white border border-stone-200 rounded-2xl p-6">
        <h2 class="font-semibold text-stone-900">Add photos</h2>
        <p class="text-sm text-stone-500 mt-1">JPEG or PNG, up to 50 MB each. Responsive JPEG + WebP variants are generated in the background.</p>

        <form wire:submit.prevent="uploadBatch" class="mt-4 flex flex-col gap-3">
            <input type="file" wire:model="uploads" multiple accept="image/*"
                   class="block w-full text-sm text-stone-700 file:min-h-[44px] file:px-4 file:mr-4 file:rounded-lg file:border-0 file:bg-emerald-50 file:text-emerald-700 file:font-semibold hover:file:bg-emerald-100">

            <div wire:loading wire:target="uploads" class="text-sm text-stone-500">Uploading…</div>

            @error('uploads.*')<p class="text-sm text-red-600">{{ $message }}</p>@enderror

            <button type="submit" @disabled(empty($uploads))
                    class="self-start inline-flex items-center min-h-[44px] px-5 rounded-lg bg-emerald-700 text-white text-sm font-semibold hover:bg-emerald-800 disabled:opacity-50">
                Upload @if(count($uploads)) {{ count($uploads) }} @endif photo(s)
            </button>
        </form>
    </div>

    {{-- Photo grid --}}
    @if($album->items->isEmpty())
        <div class="mt-8 bg-white border border-dashed border-stone-300 rounded-2xl p-10 text-center">
            <p class="text-stone-500">No photos yet. Upload the first ones above.</p>
        </div>
    @else
        <div class="mt-8"
             x-data="{ dragId: null }">
            <p class="text-xs text-stone-500 mb-3">Drag to reorder. Click a photo to edit its caption, credit and alt text.</p>
            <div class="grid gap-4"
                 style="grid-template-columns: repeat(auto-fill, minmax(min(220px, 100%), 1fr));">
                @foreach($album->items as $item)
                    <div wire:key="item-{{ $item->id }}"
                         draggable="true"
                         @dragstart="dragId = {{ $item->id }}"
                         @dragover.prevent
                         @drop.prevent="
                             if (dragId !== null && dragId !== {{ $item->id }}) {
                                 const list = Array.from($el.parentNode.children).map(el => parseInt(el.getAttribute('data-id')));
                                 const from = list.indexOf(dragId);
                                 const to = list.indexOf({{ $item->id }});
                                 const [moved] = list.splice(from, 1);
                                 list.splice(to, 0, moved);
                                 $wire.reorder(list);
                             }
                             dragId = null;
                         "
                         data-id="{{ $item->id }}"
                         class="relative bg-white border border-stone-200 rounded-xl overflow-hidden shadow-sm cursor-move">
                        <button type="button" wire:click="editItem({{ $item->id }})"
                                class="block w-full aspect-[4/3] bg-stone-100 overflow-hidden focus:outline-none focus:ring-2 focus:ring-emerald-500">
                            {{-- Caption suppressed: the card body below shows caption + credit. --}}
                            <x-media-picture :item="$item" :showCaption="false"
                                             sizes="220px" class="w-full h-full"
                                             imgClass="w-full h-full object-cover" />
                        </button>
                        <div class="p-3 text-xs text-stone-500 space-y-1">
                            <div class="truncate" title="{{ $item->caption }}">{{ $item->caption ?: '(no caption)' }}</div>
                            @if(! $item->alt)
                                <div class="text-amber-700 font-semibold">⚠ Alt text missing</div>
                            @endif
                            <div class="flex items-center justify-between gap-2 pt-1">
                                @if($album->cover_media_item_id === $item->id)
                                    <span class="inline-flex items-center rounded bg-emerald-100 text-emerald-800 px-1.5 py-0.5 font-semibold">Cover</span>
                                @else
                                    <button wire:click="setCover({{ $item->id }})" class="text-stone-500 hover:text-emerald-700">Set as cover</button>
                                @endif
                                @if($item->is_home_feature)
                                    <button wire:click="unfeature({{ $item->id }})" class="inline-flex items-center rounded bg-amber-100 text-amber-900 px-1.5 py-0.5 font-semibold hover:bg-amber-200">
                                        ★ Featured
                                    </button>
                                @else
                                    <button wire:click="openFeature({{ $item->id }})" class="text-amber-700 hover:text-amber-900">Feature on home</button>
                                @endif
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    {{-- Edit modal --}}
    @if($editingItemId)
        <div class="fixed inset-0 z-50 bg-stone-900/60 flex items-center justify-center p-4"
             wire:click.self="$set('editingItemId', null)">
            <div class="bg-white rounded-2xl max-w-lg w-full p-6">
                <h2 class="font-semibold text-stone-900 text-lg">Edit photo</h2>
                <form wire:submit.prevent="saveItem" class="mt-4 flex flex-col gap-4">
                    <label class="flex flex-col gap-1.5 text-sm font-semibold text-stone-700">
                        Alt text <span class="text-red-600">*</span>
                        <input type="text" wire:model="alt" required maxlength="300"
                               class="min-h-[44px] px-3 rounded-lg border border-stone-300 text-base font-normal">
                        <span class="text-xs font-normal text-stone-500">Describe the photo for screen-readers. Required.</span>
                    </label>
                    <label class="flex flex-col gap-1.5 text-sm font-semibold text-stone-700">
                        Caption
                        <input type="text" wire:model="caption" maxlength="500"
                               class="min-h-[44px] px-3 rounded-lg border border-stone-300 text-base font-normal">
                    </label>
                    <label class="flex flex-col gap-1.5 text-sm font-semibold text-stone-700">
                        Photo credit
                        <input type="text" wire:model="credit" maxlength="200" placeholder="Photographer"
                               class="min-h-[44px] px-3 rounded-lg border border-stone-300 text-base font-normal">
                    </label>
                    @error('alt')<p class="text-sm text-red-600">{{ $message }}</p>@enderror
                    <div class="flex items-center justify-between pt-2 border-t border-stone-100">
                        <button type="button" wire:click="deleteItem({{ $editingItemId }})"
                                wire:confirm="Delete this photo? This cannot be undone."
                                class="text-sm text-red-700 font-semibold hover:text-red-900">
                            Delete photo
                        </button>
                        <div class="flex gap-2">
                            <button type="button" wire:click="$set('editingItemId', null)"
                                    class="inline-flex items-center min-h-[44px] px-4 rounded-lg border border-stone-300 text-sm font-semibold text-stone-700 hover:bg-stone-50">
                                Cancel
                            </button>
                            <button type="submit"
                                    class="inline-flex items-center min-h-[44px] px-5 rounded-lg bg-emerald-700 text-white text-sm font-semibold hover:bg-emerald-800">
                                Save
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    @endif

    {{-- Feature-on-homepage modal --}}
    @if($featuringItemId)
        <div class="fixed inset-0 z-50 bg-stone-900/60 flex items-center justify-center p-4"
             wire:click.self="$set('featuringItemId', null)">
            <div class="bg-white rounded-2xl max-w-lg w-full p-6">
                <h2 class="font-semibold text-stone-900 text-lg">Feature on homepage</h2>
                <p class="text-sm text-stone-500 mt-1">
                    Promoting this photo replaces the current homepage feature.
                    The previous one stays in its album.
                </p>
                <form wire:submit.prevent="saveFeature" class="mt-4 flex flex-col gap-4">
                    <label class="flex flex-col gap-1.5 text-sm font-semibold text-stone-700">
                        Label pill
                        <input type="text" wire:model="home_label" required maxlength="60"
                               class="min-h-[44px] px-3 rounded-lg border border-stone-300 text-base font-normal">
                    </label>
                    <label class="flex flex-col gap-1.5 text-sm font-semibold text-stone-700">
                        Headline
                        <input type="text" wire:model="home_headline" required maxlength="200"
                               class="min-h-[44px] px-3 rounded-lg border border-stone-300 text-base font-normal">
                    </label>
                    <label class="flex flex-col gap-1.5 text-sm font-semibold text-stone-700">
                        Summary (optional)
                        <textarea wire:model="home_summary" rows="3" maxlength="500"
                                  class="px-3 py-2 rounded-lg border border-stone-300 text-base font-normal"></textarea>
                    </label>
                    <label class="flex flex-col gap-1.5 text-sm font-semibold text-stone-700">
                        Primary button links to
                        <select wire:model="home_link_type"
                                class="min-h-[44px] px-3 rounded-lg border border-stone-300 text-base font-normal">
                            @foreach(App\Models\MediaItem::LINK_TYPES as $k => $v)
                                <option value="{{ $k }}">{{ $v }}</option>
                            @endforeach
                        </select>
                    </label>
                    @if($home_link_type === 'custom' || $home_link_type === 'results')
                        <label class="flex flex-col gap-1.5 text-sm font-semibold text-stone-700">
                            URL
                            <input type="url" wire:model="home_link_url" maxlength="400"
                                   placeholder="https://…"
                                   class="min-h-[44px] px-3 rounded-lg border border-stone-300 text-base font-normal">
                        </label>
                    @endif
                    @error('home_headline')<p class="text-sm text-red-600">{{ $message }}</p>@enderror
                    <div class="flex items-center justify-end gap-2 pt-2 border-t border-stone-100">
                        <button type="button" wire:click="$set('featuringItemId', null)"
                                class="inline-flex items-center min-h-[44px] px-4 rounded-lg border border-stone-300 text-sm font-semibold text-stone-700 hover:bg-stone-50">
                            Cancel
                        </button>
                        <button type="submit"
                                class="inline-flex items-center min-h-[44px] px-5 rounded-lg bg-amber-500 text-stone-900 text-sm font-semibold hover:bg-amber-400">
                            Feature on homepage
                        </button>
                    </div>
                </form>
            </div>
        </div>
    @endif
</div>
