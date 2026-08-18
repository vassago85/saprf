<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Minishlink\WebPush\VAPID;

/**
 * `php artisan webpush:vapid` — regenerate the VAPID keypair and print
 * an `.env` snippet the operator can paste. Run once per environment;
 * regenerating will invalidate every existing browser subscription so
 * only do it during a scheduled push maintenance window.
 */
class GenerateVapidKeysCommand extends Command
{
    protected $signature = 'webpush:vapid';

    protected $description = 'Generate a new VAPID keypair for Web Push and print .env snippet.';

    public function handle(): int
    {
        $keys = VAPID::createVapidKeys();

        $this->info('Add these to your .env (server-side only for the private key):');
        $this->line('');
        $this->line('VAPID_SUBJECT=mailto:noreply@saprf.co.za');
        $this->line('VAPID_PUBLIC_KEY=' . $keys['publicKey']);
        $this->line('VAPID_PRIVATE_KEY=' . $keys['privateKey']);
        $this->line('');
        $this->warn('Regenerating VAPID keys invalidates every existing push subscription. Only rotate during a maintenance window.');

        return self::SUCCESS;
    }
}
