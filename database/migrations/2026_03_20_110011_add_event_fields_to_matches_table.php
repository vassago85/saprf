<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('matches', function (Blueprint $table) {
            $table->string('city')->nullable()->after('venue_location');
            $table->unsignedInteger('max_competitors')->nullable()->after('non_member_fee');
            $table->boolean('waitlist_enabled')->default(false)->after('max_competitors');
            $table->boolean('is_featured')->default(false)->after('waitlist_enabled');
            $table->boolean('published')->default(true)->after('is_featured');
        });
    }

    public function down(): void
    {
        Schema::table('matches', function (Blueprint $table) {
            $table->dropColumn(['city', 'max_competitors', 'waitlist_enabled', 'is_featured', 'published']);
        });
    }
};
