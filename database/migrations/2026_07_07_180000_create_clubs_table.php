<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('clubs')) {
            Schema::create('clubs', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->string('slug')->unique();
                $table->string('abbreviation', 20)->nullable();
                $table->foreignId('province_id')->nullable()->constrained('provinces')->nullOnDelete();
                $table->boolean('is_active')->default(true);
                $table->timestamps();
            });
        }

        if (! Schema::hasColumn('users', 'club_id')) {
            Schema::table('users', function (Blueprint $table) {
                $table->foreignId('club_id')
                    ->nullable()
                    ->after('province_id')
                    ->constrained('clubs')
                    ->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('users', 'club_id')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropConstrainedForeignId('club_id');
            });
        }

        Schema::dropIfExists('clubs');
    }
};
