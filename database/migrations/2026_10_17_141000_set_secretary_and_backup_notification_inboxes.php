<?php

use App\Models\Setting;
use Illuminate\Database\Migrations\Migration;

/**
 * Point contact/Reply-To and failed-backup mail at a real inbox. The old
 * federation aliases (owner@ / admin@ / exco@ saprf.co.za) bounce, and
 * blank Site Settings left backups falling through to your@example.com.
 */
return new class extends Migration
{
    public function up(): void
    {
        $defaults = [
            'secretary_email' => [
                'value' => 'paul@charsley.co.za',
                'description' => 'Secretary inbox for contact-form enquiries and member Reply-To',
            ],
            'backup_notification_email' => [
                'value' => 'paul@charsley.co.za',
                'description' => 'Inbox for database backup failure mail only',
            ],
        ];

        foreach ($defaults as $key => $payload) {
            $existing = Setting::query()->where('key', $key)->first();

            if ($existing === null) {
                Setting::query()->create([
                    'key' => $key,
                    'value' => $payload['value'],
                    'description' => $payload['description'],
                ]);

                continue;
            }

            $current = trim((string) $existing->value);
            if ($current === '' || str_ends_with(strtolower($current), '@example.com')) {
                $existing->update([
                    'value' => $payload['value'],
                    'description' => $payload['description'],
                ]);
            }
        }
    }

    public function down(): void
    {
        // Leave the inboxes in place — clearing them would reintroduce bounces.
    }
};
