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
        return AuditLog::query()->create([
            'user_id' => $actor?->id,
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
}
