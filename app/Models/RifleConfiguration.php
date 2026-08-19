<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RifleConfiguration extends Model
{
    protected $fillable = [
        'user_id',
        'nickname',
        'firearm_make_id',
        'firearm_model_id',
        'firearm_calibre_id',
        'action_description',
        'barrel_description',
        'trigger_description',
        'muzzle_brake_description',
        'bipod_description',
        'magazine_description',
        'tripod_description',
        'brass_description',
        'powder_description',
        'rangefinder_description',
        'gunsmith_description',
        'scope_mount_description',
        'bag_description',
        'chronograph_description',
        'optic_description',
        'chassis_description',
        'optic_make_id',
        'optic_model_id',
        'optic_make',
        'optic_model',
        'bullet_description',
        'bullet_make',
        'bullet_weight',
        'bullet_type',
        'barrel_length',
        'twist_rate',
        'notes',
        'primary_series',
        'show_on_profile',
        'is_active',
        'total_barrel_rounds',
    ];

    protected function casts(): array
    {
        return [
            'show_on_profile' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function make(): BelongsTo
    {
        return $this->belongsTo(FirearmMake::class, 'firearm_make_id');
    }

    public function model(): BelongsTo
    {
        return $this->belongsTo(FirearmModel::class, 'firearm_model_id');
    }

    public function calibre(): BelongsTo
    {
        return $this->belongsTo(FirearmCalibre::class, 'firearm_calibre_id');
    }

    public function opticMake(): BelongsTo
    {
        return $this->belongsTo(OpticMake::class, 'optic_make_id');
    }

    public function opticModel(): BelongsTo
    {
        return $this->belongsTo(OpticModel::class, 'optic_model_id');
    }

    public function ammoLoads(): HasMany
    {
        return $this->hasMany(AmmoLoad::class);
    }

    public function registrations(): HasMany
    {
        return $this->hasMany(MatchRegistration::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeForUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }

    public function scopeVisibleOnProfile($query)
    {
        return $query->active()
            ->whereNotNull('primary_series')
            ->where('show_on_profile', true);
    }

    public function scopeOrderMainsFirst($query, ?string $series = null)
    {
        if ($series) {
            return $query
                ->orderByRaw('CASE WHEN primary_series = ? THEN 0 ELSE 1 END', [$series])
                ->orderByRaw('CASE WHEN primary_series IS NULL THEN 1 ELSE 0 END');
        }

        return $query->orderByRaw("CASE primary_series WHEN 'PRS' THEN 0 WHEN 'PR22' THEN 1 ELSE 2 END");
    }

    public function primarySeriesLabel(): ?string
    {
        return match ($this->primary_series) {
            'PRS' => 'Main PRS',
            'PR22' => 'Main PR22',
            default => null,
        };
    }

    public function primarySeriesBadgeClasses(): string
    {
        return match ($this->primary_series) {
            'PRS' => 'bg-emerald-50 text-emerald-700 ring-emerald-600/20',
            'PR22' => 'bg-sky-50 text-sky-800 ring-sky-600/20',
            default => '',
        };
    }

    public function recalculateShotCount(): void
    {
        $total = $this->registrations()->whereNotNull('shot_count')->sum('shot_count');
        $this->update(['total_barrel_rounds' => (int) $total]);
    }

    public function displayName(): string
    {
        $parts = array_filter([
            $this->make?->name,
            $this->model?->name,
        ]);

        return $this->nickname ?: (implode(' ', $parts) ?: 'Unnamed Rifle');
    }

    /**
     * Ordered spec sheet used on the public shooter profile.
     *
     * @return array<int, array{label: string, value: string}>
     */
    public function profileSpecRows(): array
    {
        $scope = trim(($this->opticMake?->name ?? '').' '.($this->opticModel?->name ?? ''));
        $bullet = trim(implode(' ', array_filter([$this->bullet_make, $this->bullet_weight, $this->bullet_type])));

        $rows = [
            ['label' => 'Make', 'value' => $this->make?->name],
            ['label' => 'Model', 'value' => $this->model?->name],
            ['label' => 'Cartridge', 'value' => $this->calibre?->name],
            ['label' => 'Action', 'value' => $this->action_description],
            ['label' => 'Stock/Chassis', 'value' => $this->chassis_description],
            ['label' => 'Barrel', 'value' => $this->barrel_description],
            ['label' => 'Barrel Length', 'value' => $this->barrel_length],
            ['label' => 'Twist Rate', 'value' => $this->twist_rate],
            ['label' => 'Trigger', 'value' => $this->trigger_description],
            ['label' => 'Muzzle Brake', 'value' => $this->muzzle_brake_description],
            ['label' => 'Scope', 'value' => $scope !== '' ? $scope : null],
            ['label' => 'Scope Mount', 'value' => $this->scope_mount_description],
            ['label' => 'Bipod', 'value' => $this->bipod_description],
            ['label' => 'Magazine', 'value' => $this->magazine_description],
            ['label' => 'Bag', 'value' => $this->bag_description],
            ['label' => 'Bullet', 'value' => $bullet !== '' ? $bullet : null],
            ['label' => 'Powder', 'value' => $this->powder_description],
            ['label' => 'Brass', 'value' => $this->brass_description],
            ['label' => 'Rangefinder', 'value' => $this->rangefinder_description],
            ['label' => 'Chronograph', 'value' => $this->chronograph_description],
            ['label' => 'Tripod', 'value' => $this->tripod_description],
            ['label' => 'Gunsmith', 'value' => $this->gunsmith_description],
        ];

        return array_values(array_filter($rows, fn ($row) => filled($row['value'])));
    }
}
