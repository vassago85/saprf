<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Reusable named targeting rule sets ("Match Directors 2026",
     * "Ladies squad", etc.) that Exco can attach to any announcement via
     * the `saved_list` audience type. `rules` mirrors the shape stored on
     * `announcement_audiences` — the resolver expands the list inline.
     *
     * Soft deletes so a list referenced by past announcements can be
     * retired without breaking historical audits.
     */
    public function up(): void
    {
        Schema::create('saved_distribution_lists', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120)->unique();
            $table->string('description', 500)->nullable();
            $table->json('rules');
            $table->foreignId('created_by')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('saved_distribution_lists');
    }
};
