<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Individual photos inside an album.
     *
     * `path` is the original full-resolution upload. `variants` holds a
     * JSON map of width -> {jpg: path, webp: path} written by the
     * ProcessMediaItem job after upload. Until the job runs,
     * `processed_at` is null and consumers fall back to `path`.
     *
     * The homepage feature columns (`is_home_feature`, `home_label`,
     * `home_headline`, `home_summary`, `home_link_type`, `home_link_url`)
     * live on the item so a photo can be promoted straight from an album
     * without creating a parallel "featured content" table. Only one row
     * ever has `is_home_feature = true` — enforced in application code
     * (MySQL doesn't support partial unique indexes).
     */
    public function up(): void
    {
        Schema::create('media_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('media_album_id')->constrained('media_albums')->cascadeOnDelete();
            $table->string('disk')->default('public');
            $table->string('path');
            $table->json('variants')->nullable();
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->string('caption')->nullable();
            $table->string('credit')->nullable();
            // Alt text is required for accessibility — enforced at the form
            // level, nullable in the DB only so bulk-upload-then-fill flows
            // can save a draft row.
            $table->string('alt')->nullable();
            $table->date('taken_at')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamp('processed_at')->nullable();

            // Homepage feature promotion fields.
            $table->boolean('is_home_feature')->default(false);
            $table->string('home_label')->nullable();
            $table->string('home_headline')->nullable();
            $table->string('home_summary', 500)->nullable();
            $table->enum('home_link_type', ['sa_team', 'album', 'results', 'custom'])
                ->default('sa_team');
            $table->string('home_link_url')->nullable();

            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['media_album_id', 'sort_order']);
            $table->index('is_home_feature');
            $table->index('processed_at');
        });

        // Wire the cover FKs that pointed forward to media_items in the
        // earlier migrations. nullOnDelete so deleting a cover photo
        // doesn't cascade into the parent album or team.
        Schema::table('media_albums', function (Blueprint $table) {
            $table->foreign('cover_media_item_id')
                ->references('id')->on('media_items')
                ->nullOnDelete();
        });

        Schema::table('national_teams', function (Blueprint $table) {
            $table->foreign('cover_media_item_id')
                ->references('id')->on('media_items')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('national_teams', function (Blueprint $table) {
            $table->dropForeign(['cover_media_item_id']);
        });

        Schema::table('media_albums', function (Blueprint $table) {
            $table->dropForeign(['cover_media_item_id']);
        });

        Schema::dropIfExists('media_items');
    }
};
