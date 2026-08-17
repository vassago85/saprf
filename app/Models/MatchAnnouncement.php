<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MatchAnnouncement extends Model
{
    protected $fillable = [
        'match_id',
        'sender_user_id',
        'subject',
        'body',
        'recipient_count',
        'status_scope',
        'sent_at',
    ];

    protected function casts(): array
    {
        return [
            'status_scope' => 'array',
            'sent_at' => 'datetime',
            'recipient_count' => 'integer',
        ];
    }

    public function match(): BelongsTo
    {
        return $this->belongsTo(MatchEvent::class, 'match_id');
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_user_id');
    }
}
