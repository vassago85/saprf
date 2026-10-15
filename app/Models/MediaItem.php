<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * A single photo inside a MediaAlbum.
 *
 * `path` is the original full-resolution upload. `variants` is the
 * JSON payload the ProcessMediaItem job writes:
 *   {
 *     "480":  {"jpg": "media/.../480.jpg",  "webp": "media/.../480.webp"},
 *     "960":  {"jpg": "media/.../960.jpg",  "webp": "media/.../960.webp"},
 *     "1600": {"jpg": "media/.../1600.jpg", "webp": "media/.../1600.webp"}
 *   }
 * Until the job runs, variants is null and consumers fall back to `path`.
 *
 * The homepage feature columns on this table let any photo be promoted
 * to the landing-page band without a parallel "featured content" model.
 * Only ONE row ever has `is_home_feature=true` — enforced by
 * self::featureOnHomepage(), which clears the flag on all other rows
 * inside a DB transaction.
 */
class MediaItem extends Model
{
    use HasFactory;

    public const HOME_FEATURE_CACHE_KEY = 'home.feature';

    public const WIDTHS = [480, 960, 1600];

    public const LINK_TYPES = [
        'sa_team' => 'SA Team page',
        'album' => 'This album',
        'results' => 'Results',
        'custom' => 'Custom URL',
    ];

    protected $fillable = [
        'media_album_id',
        'disk',
        'path',
        'variants',
        'width',
        'height',
        'caption',
        'credit',
        'alt',
        'taken_at',
        'sort_order',
        'processed_at',
        'is_home_feature',
        'home_label',
        'home_headline',
        'home_summary',
        'home_link_type',
        'home_link_url',
        'uploaded_by',
    ];

    protected function casts(): array
    {
        return [
            'variants' => 'array',
            'width' => 'integer',
            'height' => 'integer',
            'taken_at' => 'date',
            'sort_order' => 'integer',
            'processed_at' => 'datetime',
            'is_home_feature' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        // Bust the cached featured item whenever any item changes — covers
        // caption/headline edits as well as feature flips.
        static::saved(fn () => Cache::forget(self::HOME_FEATURE_CACHE_KEY));
        static::deleted(fn () => Cache::forget(self::HOME_FEATURE_CACHE_KEY));
    }

    public function album(): BelongsTo
    {
        return $this->belongsTo(MediaAlbum::class, 'media_album_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function scopeFeatured(Builder $query): Builder
    {
        return $query->where('is_home_feature', true);
    }

    /**
     * Promote this item to the homepage feature band and clear the flag
     * on every other item in one atomic step. The previously featured
     * item stays in its album.
     */
    public function featureOnHomepage(): void
    {
        DB::transaction(function () {
            static::query()
                ->where('id', '!=', $this->id)
                ->where('is_home_feature', true)
                ->update(['is_home_feature' => false]);

            $this->is_home_feature = true;
            $this->save();
        });

        Cache::forget(self::HOME_FEATURE_CACHE_KEY);
    }

    /**
     * URL for the original full-resolution file.
     */
    public function originalUrl(): ?string
    {
        if (! $this->path) {
            return null;
        }

        return Storage::disk($this->disk ?: 'public')->url($this->path);
    }

    /**
     * URL for a specific width + format from the `variants` map.
     * Falls back to the original URL if the variant hasn't been written yet.
     */
    public function variantUrl(int $width, string $format = 'jpg'): ?string
    {
        $variants = $this->variants ?? [];
        $key = (string) $width;
        $path = $variants[$key][$format] ?? null;

        if (! $path) {
            return $this->originalUrl();
        }

        return Storage::disk($this->disk ?: 'public')->url($path);
    }

    /**
     * Build an `srcset` string for a format across all generated widths.
     * Returns the original URL when no variants exist (JPEG only).
     */
    public function srcset(string $format = 'jpg'): ?string
    {
        $variants = $this->variants ?? [];
        if ($variants === []) {
            return null;
        }

        $pairs = [];
        foreach (self::WIDTHS as $w) {
            $url = $variants[(string) $w][$format] ?? null;
            if ($url) {
                $pairs[] = Storage::disk($this->disk ?: 'public')->url($url).' '.$w.'w';
            }
        }

        return $pairs === [] ? null : implode(', ', $pairs);
    }

    /**
     * Resolve the primary button URL for the homepage band based on
     * `home_link_type` + `home_link_url`.
     */
    public function homePrimaryUrl(): string
    {
        return match ($this->home_link_type) {
            'album' => route('media.show', ['album' => $this->album?->slug ?? '']),
            'results' => $this->home_link_url ?: route('standings.public'),
            'custom' => $this->home_link_url ?: route('sa-team.index'),
            default => route('sa-team.index'),
        };
    }

    public function homePrimaryLabel(): string
    {
        return match ($this->home_link_type) {
            'album' => 'View gallery',
            'results' => 'View results',
            'custom' => 'Learn more',
            default => 'Meet the team',
        };
    }
}
