<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `email_logs` — one row per outbound email the app sends.
 *
 * Populated by MessageSending / MessageSent / MessageSendingFailed
 * listeners on the mail bus, then updated in-place by
 * MailgunWebhookController when Mailgun reports delivered / failed /
 * complained events (correlated via the email_log_id we inject into
 * X-Mailgun-Variables at send time).
 *
 * This is deliberately separate from `announcement_deliveries`. That
 * table is a *federation-broadcast* record — one row per (recipient,
 * channel) — and only exists for FederationAnnouncementNotification.
 * `email_logs` is the *transport* record — one row per email regardless
 * of what triggered it (auth, membership, contact form, broadcasts).
 * Both get updated by the same webhook so they stay in sync.
 *
 * We do NOT store password-reset tokens, invitation tokens, or OTPs.
 * Bodies for those notifications are redacted at the listener layer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_logs', function (Blueprint $table): void {
            $table->id();

            $table->string('to_email')->index();
            $table->string('to_name')->nullable();
            $table->string('from_email')->nullable();
            $table->string('reply_to')->nullable();
            $table->string('subject', 512);
            $table->string('mailer')->nullable();

            // Mailgun's Message-Id (or whatever the transport returns).
            // Not unique because dev-mode `log` mailer produces no id and
            // we still want the row.
            $table->string('message_id')->nullable()->index();

            $table->string('notification_class')->nullable()->index();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();

            // Arbitrary correlation payload — the ids we injected into
            // X-Mailgun-Variables when sending (announcement_id, delivery_id,
            // registration_id, membership_id, ...). Handy for jumping from a
            // row here into the domain object that triggered it.
            $table->json('context')->nullable();

            // queued → sent → delivered | failed | bounced | complained
            $table->string('status', 20)->default('queued')->index();
            $table->text('error')->nullable();

            // Body text is truncated to keep the table cheap. HTML preview
            // is stored so a Devops person can literally see what went out.
            $table->text('body_preview')->nullable();
            $table->longText('body_html')->nullable();
            $table->boolean('body_redacted')->default(false);

            $table->timestamp('sent_at')->nullable()->index();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamp('bounced_at')->nullable();
            $table->timestamp('complained_at')->nullable();
            $table->timestamp('opened_at')->nullable();
            $table->timestamp('clicked_at')->nullable();

            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index(['notification_class', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_logs');
    }
};
