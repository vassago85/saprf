<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Persisted record of a single outbound email.
 *
 * @property int $id
 * @property string $to_email
 * @property string|null $to_name
 * @property string|null $from_email
 * @property string|null $reply_to
 * @property string $subject
 * @property string|null $mailer
 * @property string|null $message_id
 * @property string|null $notification_class
 * @property int|null $user_id
 * @property array<string, mixed>|null $context
 * @property string $status
 * @property string|null $error
 * @property string|null $body_preview
 * @property string|null $body_html
 * @property bool $body_redacted
 * @property \Illuminate\Support\Carbon|null $sent_at
 * @property \Illuminate\Support\Carbon|null $delivered_at
 * @property \Illuminate\Support\Carbon|null $failed_at
 * @property \Illuminate\Support\Carbon|null $bounced_at
 * @property \Illuminate\Support\Carbon|null $complained_at
 * @property \Illuminate\Support\Carbon|null $opened_at
 * @property \Illuminate\Support\Carbon|null $clicked_at
 */
class EmailLog extends Model
{
    public const STATUS_QUEUED = 'queued';
    public const STATUS_SENT = 'sent';
    public const STATUS_DELIVERED = 'delivered';
    public const STATUS_FAILED = 'failed';
    public const STATUS_BOUNCED = 'bounced';
    public const STATUS_COMPLAINED = 'complained';
    public const STATUS_DISMISSED = 'dismissed';

    public const STATUSES = [
        self::STATUS_QUEUED,
        self::STATUS_SENT,
        self::STATUS_DELIVERED,
        self::STATUS_FAILED,
        self::STATUS_BOUNCED,
        self::STATUS_COMPLAINED,
        self::STATUS_DISMISSED,
    ];

    /**
     * Notification classes we can reconstruct from the log row alone
     * (recipient email / user id). Announcements need extra payload
     * we do not store here — resend those from the announcement page.
     */
    public const RESENDABLE_CLASSES = [
        \App\Notifications\ResetPasswordNotification::class,
        \App\Notifications\MemberInvitationNotification::class,
    ];

    protected $fillable = [
        'to_email',
        'to_name',
        'from_email',
        'reply_to',
        'subject',
        'mailer',
        'message_id',
        'notification_class',
        'user_id',
        'context',
        'status',
        'error',
        'body_preview',
        'body_html',
        'body_redacted',
        'sent_at',
        'delivered_at',
        'failed_at',
        'bounced_at',
        'complained_at',
        'opened_at',
        'clicked_at',
    ];

    protected function casts(): array
    {
        return [
            'context' => 'array',
            'body_redacted' => 'boolean',
            'sent_at' => 'datetime',
            'delivered_at' => 'datetime',
            'failed_at' => 'datetime',
            'bounced_at' => 'datetime',
            'complained_at' => 'datetime',
            'opened_at' => 'datetime',
            'clicked_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeStatus(Builder $query, ?string $status): Builder
    {
        if ($status === null || $status === '' || ! in_array($status, self::STATUSES, true)) {
            return $query;
        }

        return $query->where('status', $status);
    }

    public function scopeRecipientLike(Builder $query, ?string $search): Builder
    {
        if ($search === null || trim($search) === '') {
            return $query;
        }

        return $query->where('to_email', 'like', '%' . trim($search) . '%');
    }

    /**
     * Colour class for the status pill in the UI. Keeping presentation
     * here (rather than a Blade `@switch`) means the same colours are
     * used consistently on the list and detail views.
     */
    public function statusPillClasses(): string
    {
        return match ($this->status) {
            self::STATUS_DELIVERED => 'bg-emerald-100 text-emerald-800 ring-emerald-600/20',
            self::STATUS_SENT      => 'bg-sky-100 text-sky-800 ring-sky-600/20',
            self::STATUS_QUEUED    => 'bg-stone-100 text-stone-700 ring-stone-500/20',
            self::STATUS_FAILED    => 'bg-amber-100 text-amber-800 ring-amber-600/20',
            self::STATUS_BOUNCED   => 'bg-rose-100 text-rose-800 ring-rose-600/20',
            self::STATUS_COMPLAINED => 'bg-rose-100 text-rose-800 ring-rose-600/20',
            self::STATUS_DISMISSED => 'bg-stone-100 text-stone-500 ring-stone-400/20',
            default => 'bg-stone-100 text-stone-700 ring-stone-500/20',
        };
    }

    /**
     * Queued orphans (Mailgun never accepted) and hard fails can be
     * closed by an operator so they stop looking like outstanding work.
     */
    public function isOutstanding(): bool
    {
        return in_array($this->status, [self::STATUS_QUEUED, self::STATUS_FAILED], true);
    }

    public function canResend(): bool
    {
        return $this->isOutstanding()
            && $this->notification_class !== null
            && in_array($this->notification_class, self::RESENDABLE_CLASSES, true);
    }

    public function markDismissed(string $reason): void
    {
        $this->forceFill([
            'status' => self::STATUS_DISMISSED,
            'error' => mb_substr($reason, 0, 1000),
        ])->save();
    }
}
