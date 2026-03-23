<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlatformIncome extends Model
{
    protected $table = 'platform_income';

    protected $fillable = [
        'category',
        'description',
        'amount',
        'income_date',
        'source',
        'reference',
        'notes',
        'is_recurring',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'income_date' => 'date',
            'is_recurring' => 'boolean',
        ];
    }

    public const CATEGORIES = [
        'donation' => 'Donation',
        'sponsorship' => 'Sponsorship',
        'grant' => 'Grant',
        'merchandise' => 'Merchandise Sales',
        'interest' => 'Interest Income',
        'other' => 'Other Income',
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function categoryLabel(): string
    {
        return self::CATEGORIES[$this->category] ?? ucfirst($this->category);
    }
}
