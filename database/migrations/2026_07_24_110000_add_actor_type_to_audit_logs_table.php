<?php

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            // Who initiated the change: system | admin | user.
            $table->string('actor_type', 16)->nullable()->after('user_id')->index();
        });

        // Backfill history. No actor means an automated job/command.
        AuditLog::query()->whereNull('user_id')->update(['actor_type' => AuditLog::ACTOR_SYSTEM]);

        // Anyone holding a staff role, or sitting on an active provincial
        // committee, was acting with admin authority (best effort against
        // their *current* roles for historical rows).
        $staffIds = User::query()
            ->where(function ($q) {
                $q->whereHas('roles', fn ($r) => $r->whereIn('name', User::STAFF_ROLES))
                    ->orWhereHas('committeePositions', fn ($c) => $c->where('is_active', true));
            })
            ->pluck('id');

        if ($staffIds->isNotEmpty()) {
            AuditLog::query()
                ->whereIn('user_id', $staffIds)
                ->update(['actor_type' => AuditLog::ACTOR_ADMIN]);
        }

        // Everything left with an actor is an ordinary member change.
        AuditLog::query()
            ->whereNotNull('user_id')
            ->whereNull('actor_type')
            ->update(['actor_type' => AuditLog::ACTOR_USER]);
    }

    public function down(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->dropIndex(['actor_type']);
            $table->dropColumn('actor_type');
        });
    }
};
