<?php

namespace App\Models;

use App\Enums\AnnouncementCategory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NotificationPreference extends Model
{
    protected $fillable = [
        'user_id',
        'muted_email_categories',
        'push_enabled',
    ];

    protected function casts(): array
    {
        return [
            'muted_email_categories' => 'array',
            'push_enabled' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Should we send an email for this category to this user?
     * Mandatory categories (Policy change, Urgent) bypass mutes.
     */
    public function allowsEmailFor(AnnouncementCategory $category): bool
    {
        if ($category->isMandatory()) {
            return true;
        }

        $muted = $this->muted_email_categories ?? [];

        return ! in_array($category->value, $muted, true);
    }

    /**
     * Should we send a push notification for this category to this user?
     * Mandatory categories still fire even when push_enabled is false
     * (Urgent is the whole point of the channel).
     */
    public function allowsPushFor(AnnouncementCategory $category): bool
    {
        if ($category->isMandatory()) {
            return true;
        }

        return (bool) $this->push_enabled;
    }
}
