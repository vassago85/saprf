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
        'impersonated_user_id',
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

    /**
     * The member whose identity a developer had assumed when this row
     * was written. Null unless the write happened inside an impersonation
     * session. user_id remains the developer — the real actor.
     */
    public function impersonatedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'impersonated_user_id');
    }

    public function wasImpersonated(): bool
    {
        return $this->impersonated_user_id !== null;
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

    /**
     * Cache of entity_type → id → resolved subject for the current request.
     * Prevents N+1 when the same subject appears on multiple audit rows
     * (e.g. a batch of lapsed-membership entries all pointing at one member).
     */
    private static array $subjectCache = [];

    /**
     * Resolve the entity referenced by this audit log to a human-readable
     * subject (the "who" behind the entity_id).
     *
     * Returns `null` for entity types we don't know how to resolve (Setting,
     * Score, ScoreImport, Sponsor, etc.) — for those the entity_id is the
     * useful identifier already.
     *
     * The returned array shape:
     *   [
     *       'name' => 'Paul Charsley',
     *       'email' => 'p.charsley@gmail.com',
     *       'saprf_number' => 'SAPRF-1701',   // when known
     *       'edit_url' => 'https://.../user-management/890/edit',
     *       'is_deleted' => false,
     *   ]
     */
    public function resolveSubject(): ?array
    {
        if (! $this->entity_id) {
            return null;
        }

        $short = class_basename($this->entity_type ?? '');
        $cacheKey = "{$short}:{$this->entity_id}";

        if (array_key_exists($cacheKey, self::$subjectCache)) {
            return self::$subjectCache[$cacheKey];
        }

        return self::$subjectCache[$cacheKey] = match ($short) {
            'User' => $this->resolveUserSubject((int) $this->entity_id),
            'Membership' => $this->resolveMembershipSubject((int) $this->entity_id),
            default => null,
        };
    }

    /**
     * Clear the in-memory subject cache. Callers rarely need this in
     * production (request lifecycles are short) but tests and long-running
     * workers should invoke it between iterations so stale rows aren't
     * returned after DB state changes.
     */
    public static function clearSubjectCache(): void
    {
        self::$subjectCache = [];
    }

    /**
     * Prime the subject cache in bulk for a collection of audit logs. Cheap
     * way to avoid N+1 when rendering the index list.
     */
    public static function preloadSubjects(iterable $logs): void
    {
        $userIds = [];
        $membershipIds = [];

        foreach ($logs as $log) {
            if (! $log->entity_id) {
                continue;
            }
            $short = class_basename($log->entity_type ?? '');
            if ($short === 'User') {
                $userIds[] = (int) $log->entity_id;
            } elseif ($short === 'Membership') {
                $membershipIds[] = (int) $log->entity_id;
            }
        }

        if ($userIds) {
            $users = User::withTrashed()->with('membership')
                ->whereIn('id', array_unique($userIds))
                ->get();
            foreach ($users as $user) {
                self::$subjectCache["User:{$user->id}"] = [
                    'name' => $user->name,
                    'email' => $user->email,
                    'saprf_number' => $user->membership?->saprf_number,
                    'edit_url' => $user->trashed() ? null : self::userEditUrl($user->id),
                    'is_deleted' => $user->trashed(),
                ];
            }
        }

        if ($membershipIds) {
            $memberships = Membership::with(['user' => fn ($q) => $q->withTrashed()])
                ->whereIn('id', array_unique($membershipIds))
                ->get();
            foreach ($memberships as $membership) {
                $user = $membership->user;
                self::$subjectCache["Membership:{$membership->id}"] = [
                    'name' => $user?->name ?? '— member gone —',
                    'email' => $user?->email,
                    'saprf_number' => $membership->saprf_number,
                    'edit_url' => ($user && ! $user->trashed())
                        ? self::userEditUrl($user->id)
                        : null,
                    'is_deleted' => (bool) $user?->trashed(),
                ];
            }
        }
    }

    private function resolveUserSubject(int $id): ?array
    {
        $user = User::withTrashed()->with('membership')->find($id);
        if (! $user) {
            return null;
        }

        return [
            'name' => $user->name,
            'email' => $user->email,
            'saprf_number' => $user->membership?->saprf_number,
            'edit_url' => $user->trashed() ? null : self::userEditUrl($user->id),
            'is_deleted' => $user->trashed(),
        ];
    }

    private function resolveMembershipSubject(int $id): ?array
    {
        $membership = Membership::with(['user' => fn ($q) => $q->withTrashed()])->find($id);
        if (! $membership) {
            return null;
        }

        $user = $membership->user;

        return [
            'name' => $user?->name ?? '— member gone —',
            'email' => $user?->email,
            'saprf_number' => $membership->saprf_number,
            'edit_url' => ($user && ! $user->trashed()) ? self::userEditUrl($user->id) : null,
            'is_deleted' => (bool) $user?->trashed(),
        ];
    }

    private static function userEditUrl(int $userId): ?string
    {
        try {
            return route('user-management.edit', $userId);
        } catch (\Throwable) {
            return null;
        }
    }
}
