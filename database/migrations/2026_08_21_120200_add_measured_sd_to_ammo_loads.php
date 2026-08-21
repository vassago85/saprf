<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ammo_loads', function (Blueprint $table) {
            // Snapshot of the latest confirmation string fired against this
            // ammo load. The UI reads these to show e.g. "H4350 40.8 gr — 8.9
            // fps SD (n=25)" in the load picker so shooters remember which
            // recipes have been confirmed at what precision. Overwritten every
            // time a new string is saved for the load; pooling across strings
            // is a follow-up feature.
            $table->decimal('measured_sd_fps', 5, 2)->nullable()->after('velocity');
            $table->unsignedSmallInteger('measured_sd_n')->nullable()->after('measured_sd_fps');
            $table->timestamp('measured_sd_at')->nullable()->after('measured_sd_n');
            $table->foreignId('measured_sd_string_id')->nullable()->after('measured_sd_at')
                ->constrained('ammo_strings')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('ammo_loads', function (Blueprint $table) {
            $table->dropConstrainedForeignId('measured_sd_string_id');
            $table->dropColumn(['measured_sd_fps', 'measured_sd_n', 'measured_sd_at']);
        });
    }
};
