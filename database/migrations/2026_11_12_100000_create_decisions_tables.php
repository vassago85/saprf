<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Public Decision Register + anonymous member reactions.
 *
 * Two separate reaction stores by design (Member Reaction proposal):
 *   - decision_members  — who reacted (never how)
 *   - decision_tallies  — like/dislike counters only (never who)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('decisions', function (Blueprint $table) {
            $table->id();
            $table->string('title', 200);
            $table->text('body');
            $table->string('reference', 200)->nullable();
            $table->string('status', 20)->default('draft');
            $table->timestamp('published_at')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index(['status', 'published_at']);
        });

        Schema::create('decision_tallies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('decision_id')->unique()->constrained('decisions')->cascadeOnDelete();
            $table->unsignedInteger('likes')->default(0);
            $table->unsignedInteger('dislikes')->default(0);
            $table->unsignedInteger('neutrals')->default(0);
        });

        Schema::create('decision_members', function (Blueprint $table) {
            $table->foreignId('decision_id')->constrained('decisions')->cascadeOnDelete();
            $table->foreignId('member_id')->constrained('users')->cascadeOnDelete();

            $table->primary(['decision_id', 'member_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('decision_members');
        Schema::dropIfExists('decision_tallies');
        Schema::dropIfExists('decisions');
    }
};
