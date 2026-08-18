<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Which outbound channels to fan out at send time.
     * Null on existing rows means "all channels" (legacy behaviour).
     * In-app / Communications is always delivered regardless.
     */
    public function up(): void
    {
        Schema::table('announcements', function (Blueprint $table) {
            $table->json('deliver_via')->nullable()->after('requires_acknowledgement');
        });
    }

    public function down(): void
    {
        Schema::table('announcements', function (Blueprint $table) {
            $table->dropColumn('deliver_via');
        });
    }
};
