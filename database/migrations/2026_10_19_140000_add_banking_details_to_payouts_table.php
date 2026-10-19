<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Store MD banking details on the payout itself.
 *
 * Before this migration an MD could request a payout with a single POST and
 * had no way to tell SAPRF *where* the money should land. Admins had to chase
 * the MD by WhatsApp. These columns capture the account the MD nominated at
 * request time and are frozen onto the payout record (same lifecycle as the
 * statement snapshot) so admins can process the payment without the back-and-
 * forth. Nullable because historical payouts (raised before this feature) do
 * not have banking details and will not be back-filled.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payouts', function (Blueprint $table) {
            $table->string('account_holder')->nullable()->after('payment_reference');
            $table->string('bank_name', 100)->nullable()->after('account_holder');
            $table->string('account_number', 30)->nullable()->after('bank_name');
            $table->string('branch_code', 12)->nullable()->after('account_number');
        });
    }

    public function down(): void
    {
        Schema::table('payouts', function (Blueprint $table) {
            $table->dropColumn([
                'account_holder',
                'bank_name',
                'account_number',
                'branch_code',
            ]);
        });
    }
};
