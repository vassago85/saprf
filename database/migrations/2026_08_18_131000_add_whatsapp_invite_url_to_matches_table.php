<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Optional WhatsApp group invite shown to confirmed shooters after
     * they no longer owe an entry fee (paid, waived, or free).
     */
    public function up(): void
    {
        Schema::table('matches', function (Blueprint $table) {
            $table->string('whatsapp_invite_url')->nullable()->after('match_director_contact');
        });
    }

    public function down(): void
    {
        Schema::table('matches', function (Blueprint $table) {
            $table->dropColumn('whatsapp_invite_url');
        });
    }
};
