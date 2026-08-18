<?php

namespace App\Models;

use App\Enums\AudienceMode;
use App\Enums\AudienceType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AnnouncementAudience extends Model
{
    protected $fillable = [
        'announcement_id',
        'type',
        'value',
        'mode',
    ];

    protected function casts(): array
    {
        return [
            'type' => AudienceType::class,
            'mode' => AudienceMode::class,
            'value' => 'array',
        ];
    }

    public function announcement(): BelongsTo
    {
        return $this->belongsTo(Announcement::class);
    }
}
