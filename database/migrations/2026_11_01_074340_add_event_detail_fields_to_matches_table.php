<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 3.3 + 3.4 of the Events overhaul.
 *
 * - `venue_details`: optional long-form notes about the venue (parking,
 *   facilities, overnight info). Rendered as a collapsible card below Match
 *   Details only when populated.
 * - `schedule_json`, `squadding_json`, `attachments_json`: structured
 *   JSON blobs filled in by the match director. Each gets its own card
 *   when non-empty; shape is validated at the form boundary, not the DB.
 * - `show_md_contact_publicly`: boolean guard. Defaults to false so the
 *   current private-by-default behaviour is preserved for existing rows;
 *   admins can opt-in per match from the edit page.
 *
 * Reversible: `down()` drops the columns in the same order they were added.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('matches', function (Blueprint $table) {
            $table->text('venue_details')->nullable()->after('venue_location');
            $table->json('schedule_json')->nullable()->after('venue_details');
            $table->json('squadding_json')->nullable()->after('schedule_json');
            $table->json('attachments_json')->nullable()->after('squadding_json');
            $table->boolean('show_md_contact_publicly')->default(false)->after('match_director_contact');
        });
    }

    public function down(): void
    {
        Schema::table('matches', function (Blueprint $table) {
            $table->dropColumn([
                'venue_details',
                'schedule_json',
                'squadding_json',
                'attachments_json',
                'show_md_contact_publicly',
            ]);
        });
    }
};
