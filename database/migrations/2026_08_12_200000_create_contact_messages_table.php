<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contact_messages', function (Blueprint $table) {
            $table->id();
            $table->string('first_name', 100);
            $table->string('surname', 100);
            $table->string('email', 255);
            $table->string('subject', 255);
            $table->text('message');
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 500)->nullable();
            // 'clean' = normal submission, 'honeypot' = spam bot hit the
            // hidden field, 'too_fast' = submitted faster than a human
            // could plausibly complete the form.
            $table->string('spam_status', 20)->default('clean')->index();
            $table->timestamp('handled_at')->nullable()->index();
            $table->foreignId('handled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('handled_notes')->nullable();
            $table->timestamps();

            $table->index(['created_at', 'spam_status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contact_messages');
    }
};
