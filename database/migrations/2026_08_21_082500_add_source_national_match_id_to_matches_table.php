<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Links a Day-1 provincial sibling match back to the 2-day national it was
 * extracted from. Used by score upload so re-uploads of "Day 1 scores" reuse
 * the same provincial match instead of creating duplicates.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('matches', function (Blueprint $table): void {
            $table->foreignId('source_national_match_id')
                ->nullable()
                ->after('everyone_counts')
                ->constrained('matches')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('matches', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('source_national_match_id');
        });
    }
};
