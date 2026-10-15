<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Photo albums.
     *
     * Categories mirror the mockup chip row: team_sa, sa_champs, nationals,
     * provincial, other. The optional match_event_id / national_team_id FKs
     * let an album link back to the match or team-year it covers, so the
     * album page can show a "View results" / "View team" link.
     *
     * cover_media_item_id is added once media_items exists.
     */
    public function up(): void
    {
        Schema::create('media_albums', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('slug')->unique();
            $table->enum('category', ['team_sa', 'sa_champs', 'nationals', 'provincial', 'other'])
                ->default('other');
            $table->foreignId('match_event_id')->nullable()->constrained('matches')->nullOnDelete();
            $table->foreignId('national_team_id')->nullable()->constrained('national_teams')->nullOnDelete();
            $table->date('event_date')->nullable();
            $table->string('venue_label')->nullable();
            $table->unsignedBigInteger('cover_media_item_id')->nullable();
            $table->boolean('is_published')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['category', 'is_published']);
            $table->index('is_published');
            $table->index('sort_order');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media_albums');
    }
};
