<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlatformPayoutDismissal extends Model
{
    protected $fillable = [
        'period_start',
        'period_end',
        'platform_fees_amount',
        'entry_count',
        'reason',
        'dismissed_by',
        'dismissed_at',
    ];

    protected function casts(): array
    {
        return [
            'period_start' => 'date',
            'period_end' => 'date',
            'platform_fees_amount' => 'decimal:2',
            'entry_count' => 'integer',
            'dismissed_at' => 'datetime',
        ];
    }

    public function dismisser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'dismissed_by');
    }
}
