<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlatformExpense extends Model
{
    protected $fillable = [
        'category',
        'description',
        'amount',
        'expense_date',
        'reference',
        'vendor',
        'notes',
        'is_recurring',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'expense_date' => 'date',
            'is_recurring' => 'boolean',
        ];
    }

    public const CATEGORIES = [
        'equipment' => 'Equipment',
        'bank_charges' => 'Bank Charges',
        'software' => 'Software & Subscriptions',
        'travel' => 'Travel & Transport',
        'marketing' => 'Marketing & Advertising',
        'insurance' => 'Insurance',
        'legal' => 'Legal & Compliance',
        'hosting' => 'Hosting & Infrastructure',
        'printing' => 'Printing & Stationery',
        'events' => 'Event Costs',
        'other' => 'Other',
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
