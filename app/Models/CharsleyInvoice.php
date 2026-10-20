<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CharsleyInvoice extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_CREATED = 'created';

    public const STATUS_SENT = 'sent';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'period',
        'entry_fee',
        'entries',
        'total',
        'invoice_ninja_id',
        'invoice_number',
        'status',
        'error',
        'sent_at',
    ];

    protected function casts(): array
    {
        return [
            'entry_fee' => 'decimal:2',
            'entries' => 'integer',
            'total' => 'decimal:2',
            'sent_at' => 'datetime',
        ];
    }
}
