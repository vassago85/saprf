<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class SavedDistributionList extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'name',
        'description',
        'rules',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'rules' => 'array',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
