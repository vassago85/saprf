<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * National-team YEAR records.
     *
     * `national_team_appearances` already stores one row per person per
     * championship, but the shared team-level metadata (venue, dates,
     * cover photo) had nowhere to live and was duplicated string-per-string
     * across every appearance in the roster. This table lifts that header
     * data out so Exco edits it once per team and every appearance in that
     * roster inherits it.
     *
     * `discipline` splits Centrefire from Rimfire — the two IPRF world
     * championships that run in the same calendar year. The uniqueness
     * constraint is (year, championship_name) rather than (year, discipline)
     * so an edge-case third championship in a year is still representable
     * via a different championship_name.
     */
    public function up(): void
    {
        Schema::create('national_teams', function (Blueprint $table) {
            $table->id();
            $table->smallInteger('year');
            $table->string('championship_name');
            $table->enum('discipline', ['centrefire', 'rimfire'])->default('centrefire');
            $table->string('venue')->nullable();
            $table->string('city')->nullable();
            $table->char('host_country', 2)->nullable();
            $table->date('starts_on')->nullable();
            $table->date('ends_on')->nullable();
            // cover_media_item_id is added later (after media_items exists)
            // via the media_items migration to keep the FK direction clean.
            $table->unsignedBigInteger('cover_media_item_id')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['year', 'championship_name']);
            $table->index('year');
            $table->index(['year', 'discipline']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('national_teams');
    }
};
