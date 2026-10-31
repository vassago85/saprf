<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('charsley_invoices', function (Blueprint $table) {
            if (! Schema::hasColumn('charsley_invoices', 'paid_at')) {
                $table->timestamp('paid_at')->nullable()->after('sent_at');
                $table->index('paid_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('charsley_invoices', function (Blueprint $table) {
            if (Schema::hasColumn('charsley_invoices', 'paid_at')) {
                $table->dropIndex(['paid_at']);
                $table->dropColumn('paid_at');
            }
        });
    }
};
