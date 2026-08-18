<?php

namespace App\Models;

use App\Enums\AnnouncementCategory;
use App\Enums\AnnouncementPriority;
use App\Enums\AnnouncementStatus;
use App\Enums\DeliveryChannel;
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
        'deliver_via',
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
            'deliver_via' => 'array',
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

    /**
     * Persist the composer checkboxes. In-app is always included.
     * Omitting the field (legacy callers / tests) means all channels.
     *
     * @param  array<int, string>|null  $selected
     * @return array<int, string>
     */
    public static function normalizeDeliverVia(?array $selected): array
    {
        $allowed = [
            DeliveryChannel::Database->value,
            DeliveryChannel::Mail->value,
            DeliveryChannel::WebPush->value,
        ];

        $selected = array_values(array_intersect($allowed, $selected ?? []));

        if (! in_array(DeliveryChannel::Database->value, $selected, true)) {
            $selected[] = DeliveryChannel::Database->value;
        }

        return $selected;
    }

    /**
     * Whether this send should fan out on $channel.
     * Null / empty deliver_via is legacy "all channels".
     * In-app is always on so the Communications archive stays complete.
     */
    public function deliversVia(DeliveryChannel $channel): bool
    {
        if ($channel === DeliveryChannel::Database) {
            return true;
        }

        $via = $this->deliver_via;
        if (! is_array($via) || $via === []) {
            return true;
        }

        return in_array($channel->value, $via, true);
    }
}
