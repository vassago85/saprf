<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Persisted copy of every /contact submission so nothing is lost if mail
 * delivery to admins fails. Rows marked as `spam_status != 'clean'` are
 * kept for a while (short-term audit + false-positive review) but are
 * never notified about.
 */
class ContactMessage extends Model
{
    public const SPAM_CLEAN = 'clean';
    public const SPAM_HONEYPOT = 'honeypot';
    public const SPAM_TOO_FAST = 'too_fast';
    /** Message body contained a URL and the sender didn't claim they were a human, OR body contained 2+ URLs. */
    public const SPAM_URL_FLOOD = 'url_flood';
    /** Message body matched one of our brittle spam-keyword patterns (porn, viagra, SEO shill wording). */
    public const SPAM_KEYWORD = 'keyword';
    /** Sender email domain matched the disposable-mail blocklist (fastemail.digital, mailinator.*, etc.). */
    public const SPAM_DISPOSABLE_EMAIL = 'disposable_email';
    /** Cloudflare Turnstile challenge failed (missing token, bad token, or verification call errored). */
    public const SPAM_TURNSTILE_FAILED = 'turnstile_failed';

    /**
     * Every non-clean spam_status value. Used by the admin inbox dropdown so
     * we don't have to maintain the list in two places.
     *
     * @return array<string, string>  value => human label
     */
    public static function spamStatusOptions(): array
    {
        return [
            self::SPAM_HONEYPOT => 'Honeypot',
            self::SPAM_TOO_FAST => 'Too fast',
            self::SPAM_URL_FLOOD => 'Too many URLs',
            self::SPAM_KEYWORD => 'Spam keywords',
            self::SPAM_DISPOSABLE_EMAIL => 'Disposable email',
            self::SPAM_TURNSTILE_FAILED => 'Failed Turnstile',
        ];
    }

    protected $fillable = [
        'first_name',
        'surname',
        'email',
        'subject',
        'message',
        'ip_address',
        'user_agent',
        'spam_status',
        'handled_at',
        'handled_by',
        'handled_notes',
    ];

    protected function casts(): array
    {
        return [
            'handled_at' => 'datetime',
        ];
    }

    public function handler(): BelongsTo
    {
        return $this->belongsTo(User::class, 'handled_by');
    }

    public function scopeClean($query)
    {
        return $query->where('spam_status', self::SPAM_CLEAN);
    }

    public function scopeUnhandled($query)
    {
        return $query->whereNull('handled_at');
    }

    public function fullName(): string
    {
        return trim($this->first_name.' '.$this->surname);
    }

    public function isSpam(): bool
    {
        return $this->spam_status !== self::SPAM_CLEAN;
    }
}
