<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\User;

class AuditLogService
{
    public function log(
        ?User $actor,
        string $actionType,
        string $entityType,
        ?int $entityId = null,
        ?array $oldValue = null,
        ?array $newValue = null,
        ?string $reason = null,
    ): AuditLog {
        $impersonatedUserId = null;

        // During developer impersonation the $actor callers pass is almost
        // always the TARGET (auth()->user() flipped). Re-attribute the
        // row to the developer so POPIA subject-access and the admin
        // filter show who actually clicked, and keep the assumed identity
        // on impersonated_user_id. Start/stop events already pass the
        // developer as $actor and run outside an active session key.
        $impersonator = $this->activeImpersonator($actor, $actionType);
        if ($impersonator !== null) {
            $impersonatedUserId = $actor?->id;
            $actor = $impersonator;
        }

        return AuditLog::query()->create([
            'user_id' => $actor?->id,
            'impersonated_user_id' => $impersonatedUserId,
            'actor_type' => $this->classifyActor($actor),
            'action_type' => $actionType,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'old_value' => $oldValue,
            'new_value' => $newValue,
            'reason' => $reason,
            'created_at' => now(),
        ]);
    }

    /**
     * Decide whether a change was made by the system (no actor), a staff member
     * (admin authority) or an ordinary member.
     */
    private function classifyActor(?User $actor): string
    {
        if (! $actor) {
            return AuditLog::ACTOR_SYSTEM;
        }

        return $actor->isStaffMember() ? AuditLog::ACTOR_ADMIN : AuditLog::ACTOR_USER;
    }

    /**
     * Developer currently sitting behind the impersonation banner, or
     * null if this write is not happening inside an impersonation.
     */
    private function activeImpersonator(?User $actor, string $actionType): ?User
    {
        if (in_array($actionType, ['impersonation.started', 'impersonation.stopped'], true)) {
            return null;
        }

        $impersonatorId = $this->sessionImpersonatorId();
        if ($impersonatorId === null) {
            return null;
        }

        // Actor is already the developer (or there is no actor at all —
        // a queued job). Don't rewrite those rows.
        if ($actor === null || (int) $actor->id === $impersonatorId) {
            return null;
        }

        return User::query()->find($impersonatorId);
    }

    private function sessionImpersonatorId(): ?int
    {
        if (! app()->bound('session')) {
            return null;
        }

        try {
            $id = session('impersonator_id');
        } catch (\Throwable) {
            return null;
        }

        return $id ? (int) $id : null;
    }
}
