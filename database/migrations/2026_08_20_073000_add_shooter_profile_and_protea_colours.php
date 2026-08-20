<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Public shooter profile + national-team history.
     *
     * Three concerns bundled into one migration because they ship as a single
     * feature (public shooter profile at /shooters/{saprfNumber}):
     *
     *   1. users.public_profile_visibility — POPIA opt-out. Adults default
     *      to 'public' (matches today's /standings/… behaviour, which is
     *      already publicly linkable). Juniors default to 'members_only'.
     *      'hidden' is a hard 404 for guests.
     *
     *   2. selection_cycles gains host_country + event_start/end dates so
     *      future national-team appearances created from a cycle can
     *      auto-populate the country and championship without Exco
     *      re-entering it.
     *
     *   3. national_team_appearances — one row per year a shooter shot for
     *      South Africa at an IPRF world championship (or other national
     *      event). The `awarded_colours` flag marks the ONE appearance that
     *      granted the shooter their Protea Colours (usually the first,
     *      but Exco can override). Kept separate from selection_athletes
     *      because SASCOC has been awarding colours long before this
     *      platform existed (2015, 2018, 2022 …) — those historical rows
     *      have no SelectionCycle to hang from. selection_cycle_id is
     *      optional so post-2026 rows can still inherit championship
     *      metadata from the cycle they came out of.
     *
     *      Invariant: at most one row per user has awarded_colours=true.
     *      Enforced in application code (MySQL doesn't support partial
     *      unique indexes) — see NationalTeamAppearanceController.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->enum('public_profile_visibility', ['public', 'members_only', 'hidden'])
                ->default('public')
                ->after('is_active');
        });

        DB::table('users')
            ->where('is_managed_account', true)
            ->where('managed_relationship', 'junior')
            ->update(['public_profile_visibility' => 'members_only']);

        Schema::table('selection_cycles', function (Blueprint $table) {
            $table->char('host_country', 2)->nullable()->after('championship_name');
            $table->date('event_start_date')->nullable()->after('host_country');
            $table->date('event_end_date')->nullable()->after('event_start_date');
        });

        Schema::create('national_team_appearances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->smallInteger('year');
            // Division at time of appearance. Nullable + label fallback
            // so Exco can enter historical rows for divisions that no
            // longer exist without polluting the divisions table.
            $table->foreignId('division_id')->nullable()->constrained()->nullOnDelete();
            $table->string('division_label')->nullable();
            $table->string('championship_name');
            $table->char('host_country', 2)->nullable();
            $table->unsignedSmallInteger('placing')->nullable();
            $table->foreignId('selection_cycle_id')->nullable()->constrained()->nullOnDelete();
            // True on the ONE appearance that granted this shooter their
            // Protea Colours. Application code enforces the "at most one
            // per user" invariant.
            $table->boolean('awarded_colours')->default(false);
            $table->date('appeared_at');
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'year']);
            $table->index(['user_id', 'awarded_colours']);
            $table->index('year');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('national_team_appearances');

        Schema::table('selection_cycles', function (Blueprint $table) {
            $table->dropColumn(['host_country', 'event_start_date', 'event_end_date']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('public_profile_visibility');
        });
    }
};
