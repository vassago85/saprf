<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

/**
 * The YEAR record for a national team — e.g. "2026 IPRF World Championship
 * (Centrefire)". One row per championship. The appearances table hangs off
 * this via `national_team_id`.
 *
 * This model lifts the venue + dates + cover-photo metadata out of each
 * appearance so Exco edits it once per team.
 */
class NationalTeam extends Model
{
    use HasFactory;

    protected $fillable = [
        'year',
        'championship_name',
        'discipline',
        'venue',
        'city',
        'host_country',
        'starts_on',
        'ends_on',
        'cover_media_item_id',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'starts_on' => 'date',
            'ends_on' => 'date',
        ];
    }

    public function appearances(): HasMany
    {
        return $this->hasMany(NationalTeamAppearance::class);
    }

    /**
     * All albums that have been linked to this team year. Used by the
     * admin cover-photo picker so Exco can pick any photo already
     * uploaded for the team.
     */
    public function albums(): HasMany
    {
        return $this->hasMany(MediaAlbum::class);
    }

    public function cover(): BelongsTo
    {
        return $this->belongsTo(MediaItem::class, 'cover_media_item_id');
    }

    /**
     * All MediaItems reachable via any linked album. Used for the picker.
     */
    public function albumItems(): HasManyThrough
    {
        return $this->hasManyThrough(
            MediaItem::class,
            MediaAlbum::class,
            'national_team_id', // FK on media_albums
            'media_album_id',   // FK on media_items
            'id',
            'id'
        );
    }

    public function hostCountryName(): ?string
    {
        if (! $this->host_country) {
            return null;
        }

        return NationalTeamAppearance::HOST_COUNTRIES[$this->host_country] ?? $this->host_country;
    }

    /**
     * "23–28 September 2026" style; falls back to just the start, or null.
     */
    public function datesLabel(): ?string
    {
        if (! $this->starts_on) {
            return null;
        }

        if (! $this->ends_on || $this->starts_on->equalTo($this->ends_on)) {
            return $this->starts_on->format('j F Y');
        }

        $sameMonth = $this->starts_on->isSameMonth($this->ends_on);
        $sameYear = $this->starts_on->isSameYear($this->ends_on);

        if ($sameMonth) {
            return $this->starts_on->format('j').'–'.$this->ends_on->format('j F Y');
        }

        if ($sameYear) {
            return $this->starts_on->format('j M').' – '.$this->ends_on->format('j M Y');
        }

        return $this->starts_on->format('j M Y').' – '.$this->ends_on->format('j M Y');
    }

    /**
     * The appearance flagged with role=captain, if any.
     */
    public function captain(): ?NationalTeamAppearance
    {
        return $this->appearances()->where('role', 'captain')->with('user')->first();
    }

    /**
     * The appearance flagged with role=manager, if any.
     */
    public function manager(): ?NationalTeamAppearance
    {
        return $this->appearances()->where('role', 'manager')->with('user')->first();
    }

    /**
     * Shooting squad size — shooters + captain (captain is also a shooter),
     * excluding manager / official roles.
     */
    public function squadSize(): int
    {
        return $this->appearances()
            ->whereIn('role', ['shooter', 'captain'])
            ->count();
    }
}
