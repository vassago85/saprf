<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ExCo members can hold a named portfolio (Chair, Secretary, Treasurer,
 * Events Schedule, Rules & Technical, Legal Adviser, PR22 Chair, etc.).
 *
 * Deliberately a free-text string, not an enum: the volunteer roster
 * changes as portfolios are added or renamed mid-year, and requiring a
 * code deploy for a new title is heavier than any typo-safety benefit.
 * The admin UI surfaces the current known titles via a <datalist>
 * suggestion, so typos are still discouraged.
 *
 * Null / empty = "member, no specific portfolio".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('exco_position')->nullable()->after('email_complained_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('exco_position');
        });
    }
};
