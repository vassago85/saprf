<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * A photo album. Categories match the public filter chips:
 * team_sa, sa_champs, nationals, provincial, other.
 *
 * Optional links to a MatchEvent and/or NationalTeam let an album back-
 * reference the thing it documents, so the album page can offer
 * "View results" / "View team" buttons.
 */
class MediaAlbum extends Model
{
    use HasFactory;

    public const CATEGORIES = [
        'team_sa' => 'Team SA',
        'sa_champs' => 'SA Champs',
        'nationals' => 'Nationals',
        'provincial' => 'Provincial',
        'other' => 'Other',
    ];

    protected $fillable = [
        'title',
        'slug',
        'category',
        'match_event_id',
        'national_team_id',
        'event_date',
        'venue_label',
        'cover_media_item_id',
        'is_published',
        'sort_order',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'event_date' => 'date',
            'is_published' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        // Auto-slug on save so admins only type the title. Preserves an
        // explicit slug if one is set.
        static::saving(function (self $album) {
            if (empty($album->slug) && $album->title) {
                $base = Str::slug($album->title);
                $slug = $base;
                $i = 2;
                while (self::where('slug', $slug)->where('id', '!=', $album->id)->exists()) {
                    $slug = $base.'-'.$i++;
                }
                $album->slug = $slug;
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function items(): HasMany
    {
        return $this->hasMany(MediaItem::class)->orderBy('sort_order')->orderBy('id');
    }

    public function cover(): BelongsTo
    {
        return $this->belongsTo(MediaItem::class, 'cover_media_item_id');
    }

    public function matchEvent(): BelongsTo
    {
        return $this->belongsTo(MatchEvent::class, 'match_event_id');
    }

    public function nationalTeam(): BelongsTo
    {
        return $this->belongsTo(NationalTeam::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('is_published', true);
    }

    public function categoryLabel(): string
    {
        return self::CATEGORIES[$this->category] ?? ucfirst($this->category);
    }
}
