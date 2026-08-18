<?php

use App\Enums\AnnouncementCategory;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Introduces per-announcement retention semantics + optional match link.
 *
 * `retention` decides what happens to an announcement once its "current"
 * window closes. Three modes:
 *
 *   permanent        — never auto-hides. Policy / AGM / Urgent live here.
 *                      Recent tab shows for a 60-day window, Archive shows
 *                      forever (until retracted).
 *   expires_on_date  — hides from Recent tab after `expires_at`. Still
 *                      appears in Archive. Backs the routine Announcement,
 *                      Match calendar, Platform update categories.
 *   match_scoped     — hides everywhere the moment the linked match's
 *                      status flips to `completed` or `cancelled`. This
 *                      is how MD-to-entrants bulletins auto-vanish when
 *                      the match wraps up.
 *
 * `match_id` is nullable because 95% of federation announcements have
 * nothing to do with a specific match — only match_scoped rows will
 * populate it. Foreign key so a match hard-delete doesn't leave an
 * orphaned reference.
 *
 * Backfill: existing rows are given a retention that matches their
 * category's default (mandatory categories → permanent, everything else
 * → expires_on_date). No existing row has `match_scoped` because the
 * match_bulletin category doesn't exist yet — the follow-up backfill
 * migration handles copying legacy match_announcements rows in.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('announcements', function (Blueprint $table) {
            $table->string('retention', 30)
                ->default('expires_on_date')
                ->after('category');

            $table->foreignId('match_id')
                ->nullable()
                ->after('retention')
                ->constrained('matches')
                ->nullOnDelete();

            // Composite index geared at the Inbox/Archive scopes: they
            // filter on retention + join on match_id → matches.status.
            $table->index(['retention', 'match_id']);
        });

        // Backfill per category. Mandatory categories become permanent;
        // everything else stays on the default `expires_on_date` we just
        // set above. We update in a single UPDATE per category to keep
        // the migration fast even on a big table.
        $mandatory = collect(AnnouncementCategory::cases())
            ->filter(fn ($c) => $c->isMandatory())
            ->map(fn ($c) => $c->value)
            ->values()
            ->all();

        if (! empty($mandatory)) {
            DB::table('announcements')
                ->whereIn('category', $mandatory)
                ->update(['retention' => 'permanent']);
        }

        // `agm_governance` isn't mandatory in the enum sense but still
        // "important context that shouldn't age out" per the retention
        // design, so it gets permanent too.
        DB::table('announcements')
            ->where('category', 'agm_governance')
            ->update(['retention' => 'permanent']);
    }

    public function down(): void
    {
        Schema::table('announcements', function (Blueprint $table) {
            $table->dropIndex(['retention', 'match_id']);
            $table->dropForeign(['match_id']);
            $table->dropColumn(['retention', 'match_id']);
        });
    }
};
