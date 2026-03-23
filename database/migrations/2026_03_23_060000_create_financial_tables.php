<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payouts', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 30)->unique();
            $table->string('payee_type', 30); // match_director, saprf
            $table->foreignId('payee_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('match_id')->nullable()->constrained('matches')->nullOnDelete();
            $table->date('period_start')->nullable();
            $table->date('period_end')->nullable();
            $table->decimal('gross_amount', 12, 2)->default(0);
            $table->decimal('fees_deducted', 12, 2)->default(0);
            $table->decimal('net_amount', 12, 2)->default(0);
            $table->string('status', 20)->default('pending'); // pending, paid, partial
            $table->decimal('paid_amount', 12, 2)->default(0);
            $table->dateTime('paid_at')->nullable();
            $table->string('payment_reference')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['payee_type', 'status']);
            $table->index('match_id');
        });

        Schema::create('payout_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payout_id')->constrained()->cascadeOnDelete();
            $table->string('source_type', 50); // match_registration, membership
            $table->unsignedBigInteger('source_id');
            $table->string('description');
            $table->decimal('gross_amount', 10, 2)->default(0);
            $table->decimal('platform_fee', 10, 2)->default(0);
            $table->decimal('gateway_fee', 10, 2)->default(0);
            $table->decimal('saprf_fee', 10, 2)->default(0);
            $table->decimal('net_amount', 10, 2)->default(0);
            $table->timestamps();

            $table->index('payout_id');
            $table->index(['source_type', 'source_id']);
        });

        Schema::create('financial_transactions', function (Blueprint $table) {
            $table->id();
            $table->string('type', 30); // payment, refund, adjustment, payout
            $table->string('source_type', 50); // match_registration, membership, payout
            $table->unsignedBigInteger('source_id');
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('amount', 12, 2);
            $table->string('description');
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['source_type', 'source_id']);
            $table->index('type');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('financial_transactions');
        Schema::dropIfExists('payout_items');
        Schema::dropIfExists('payouts');
    }
};
