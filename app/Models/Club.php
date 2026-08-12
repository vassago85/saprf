<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Club extends Model
{
    protected $fillable = [
        'name',
        'slug',
        'abbreviation',
        'province_id',
        'is_active',
        'saprf_recognised',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'saprf_recognised' => 'boolean',
        ];
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function province(): BelongsTo
    {
        return $this->belongsTo(Province::class);
    }

    public function scopeSaprfRecognised($query)
    {
        return $query->where('saprf_recognised', true);
    }

    /**
     * Find a club by (case-insensitive) name or create it. Returns null for
     * blank / placeholder values such as "Still need to join a club".
     */
    public static function findOrCreateByName(?string $name): ?self
    {
        $name = trim((string) $name);

        if ($name === '' || self::isPlaceholderName($name)) {
            return null;
        }

        $existing = self::whereRaw('LOWER(name) = ?', [strtolower($name)])->first();
        if ($existing) {
            return $existing;
        }

        return self::create([
            'name' => $name,
            'slug' => self::uniqueSlug($name),
            'abbreviation' => self::extractAbbreviation($name),
        ]);
    }

    private static function isPlaceholderName(string $name): bool
    {
        return (bool) preg_match(
            '/^(still need to join|no club|none|n\/?a|unknown|tbc|-+)$/i',
            trim($name),
        ) || str_contains(strtolower($name), 'still need to join');
    }

    private static function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'club';
        $slug = $base;
        $i = 2;
        while (self::where('slug', $slug)->exists()) {
            $slug = $base.'-'.$i++;
        }

        return $slug;
    }

    /**
     * Pull a bracketed abbreviation out of a name, e.g.
     * "Pretoria Precision Rifle Club (PPRC)" => "PPRC".
     */
    private static function extractAbbreviation(string $name): ?string
    {
        if (preg_match('/\(([A-Za-z0-9&\- ]{2,20})\)\s*$/', $name, $m)) {
            return trim($m[1]);
        }

        return null;
    }
}
