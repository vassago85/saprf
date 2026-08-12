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
