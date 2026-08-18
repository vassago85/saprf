<?php

namespace App\Enums;

/**
 * Terminal + interim states for a single (recipient, channel) delivery
 * row. `queued` is written the moment the fan-out job persists the row;
 * the state moves to `sent` when the channel handler completes, or
 * `failed` / `bounced` if the channel reports back an error.
 *
 *   queued    → row created, waiting for the channel job to run
 *   sent      → channel handler ran without throwing (mail dispatched
 *               to Mailgun, push endpoint accepted the payload, DB row
 *               inserted for in-app)
 *   delivered → reserved for future upstream delivery receipts (e.g.
 *               Mailgun `delivered` webhook). Not written by the
 *               current send pipeline.
 *   failed    → channel handler threw; `error` captures the message
 *   bounced   → mail hard-bounced or push endpoint returned 404/410
 */
enum DeliveryStatus: string
{
    case Queued = 'queued';
    case Sent = 'sent';
    case Delivered = 'delivered';
    case Failed = 'failed';
    case Bounced = 'bounced';

    public function label(): string
    {
        return match ($this) {
            self::Queued => 'Queued',
            self::Sent => 'Sent',
            self::Delivered => 'Delivered',
            self::Failed => 'Failed',
            self::Bounced => 'Bounced',
        };
    }
}
