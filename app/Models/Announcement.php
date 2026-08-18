<?php

namespace App\Models;

use App\Enums\AnnouncementCategory;
use App\Enums\AnnouncementPriority;
use App\Enums\AnnouncementStatus;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Announcement extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'title',
        'body',
        'category',
        'priority',
        'requires_acknowledgement',
        'status',
        'created_by',
        'approved_by',
        'approved_at',
        'send_at',
        'published_at',
        'expires_at',
        'sent_at',
        'recipient_count',
    ];

    protected function casts(): array
    {
        return [
            'category' => AnnouncementCategory::class,
            'priority' => AnnouncementPriority::class,
            'status' => AnnouncementStatus::class,
            'requires_acknowledgement' => 'boolean',
            'approved_at' => 'datetime',
            'send_at' => 'datetime',
            'published_at' => 'datetime',
            'expires_at' => 'datetime',
            'sent_at' => 'datetime',
            'recipient_count' => 'integer',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function audiences(): HasMany
    {
        return $this->hasMany(AnnouncementAudience::class);
    }

    public function recipients(): HasMany
    {
        return $this->hasMany(AnnouncementRecipient::class);
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(AnnouncementAttachment::class);
    }

    /**
     * Whether this announcement still needs a second Exco/Chair to
     * approve before send. Only Policy change composed by an author who
     * is Exco but not Chair triggers the requirement.
     */
    public function needsApproval(): bool
    {
        if ($this->category !== AnnouncementCategory::PolicyChange) {
            return false;
        }

        if ($this->approved_by !== null) {
            return false;
        }

        $creator = $this->creator;

        return $creator === null || ! $creator->isChair();
    }

    protected function isMandatory(): Attribute
    {
        return Attribute::get(fn () => $this->category->isMandatory());
    }
}
