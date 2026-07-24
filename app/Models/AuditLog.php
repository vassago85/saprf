<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuditLog extends Model
{
    public $timestamps = false;

    /** Change initiated by an automated job/command (no human actor). */
    public const ACTOR_SYSTEM = 'system';

    /** Change made by a staff member acting with admin authority. */
    public const ACTOR_ADMIN = 'admin';

    /** Change made by an ordinary member (typically to their own data). */
    public const ACTOR_USER = 'user';

    public const ACTOR_TYPES = [self::ACTOR_USER, self::ACTOR_ADMIN, self::ACTOR_SYSTEM];

    protected $fillable = [
        'user_id',
        'actor_type',
        'action_type',
        'entity_type',
        'entity_id',
        'old_value',
        'new_value',
        'reason',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'old_value' => 'array',
            'new_value' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeActorType($query, ?string $type)
    {
        return in_array($type, self::ACTOR_TYPES, true)
            ? $query->where('actor_type', $type)
            : $query;
    }

    /**
     * Human label for the actor category, falling back to "User" for legacy
     * rows that predate the column.
     */
    public function actorLabel(): string
    {
        return match ($this->actor_type) {
            self::ACTOR_SYSTEM => 'System',
            self::ACTOR_ADMIN => 'Admin',
            default => 'User',
        };
    }
}
