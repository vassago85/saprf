<?php

namespace App\Models;

use App\Enums\AnnouncementCategory;
use App\Enums\AnnouncementPriority;
use App\Enums\AnnouncementRetention;
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
        'retention',
        'match_id',
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
        'retracted_at',
        'retracted_by',
        'retraction_reason',
    ];

    protected function casts(): array
    {
        return [
            'category' => AnnouncementCategory::class,
            'retention' => AnnouncementRetention::class,
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
            'retracted_at' => 'datetime',
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

    public function retractor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'retracted_by');
    }

    /**
     * The match this announcement is scoped to, when it's an MD bulletin.
     * `match_id` is null for every federation-wide announcement so this
     * relation returns null for those. Only `retention = match_scoped`
     * rows require the FK to be set.
     */
    public function matchEvent(): BelongsTo
    {
        return $this->belongsTo(MatchEvent::class, 'match_id');
    }

    public function isRetracted(): bool
    {
        return $this->retracted_at !== null;
    }

    /**
     * Scope: filter down to announcements a member is allowed to see in
     * their /communications archive. Excludes retracted rows so a
     * mistake-send disappears from the app even though the email itself
     * is already in inboxes.
     *
     * Admin-facing lists intentionally don't call this — retracted rows
     * stay visible to Exco/Chair with a "Retracted" badge so the audit
     * trail is one query away.
     */
    public function scopeVisibleToMembers($query)
    {
        return $query->whereNull('retracted_at');
    }

    /**
     * Announcements that belong on the member's "Inbox" tab: they are
     * currently live for this user. Applied via `whereHas('announcement')`
     * from `CommunicationsController::index` so the tab count matches
     * exactly what the recipient rows the query returns.
     *
     * The three retention branches:
     *
     *   permanent       → visible while `sent_at >= now - 60 days`. Older
     *                     permanent items age out of Inbox but remain in
     *                     Archive.
     *   expires_on_date → visible while `expires_at` is null (no expiry
     *                     was set) or still in the future.
     *   match_scoped    → visible while the linked match is not
     *                     `completed` or `cancelled`. A match_scoped row
     *                     without a valid match FK is treated as broken
     *                     and hidden (whereHas returns false).
     */
    public function scopeInbox($query)
    {
        return $query
            ->whereNull('retracted_at')
            ->whereNotNull('sent_at')
            ->where(function ($q) {
                $q->where(function ($qq) {
                    $qq->where('retention', AnnouncementRetention::Permanent->value)
                        ->where('sent_at', '>=', now()->subDays(60));
                })->orWhere(function ($qq) {
                    $qq->where('retention', AnnouncementRetention::ExpiresOnDate->value)
                        ->where(function ($qqq) {
                            $qqq->whereNull('expires_at')
                                ->orWhere('expires_at', '>', now());
                        });
                })->orWhere(function ($qq) {
                    $qq->where('retention', AnnouncementRetention::MatchScoped->value)
                        ->whereHas('matchEvent', function ($qqq) {
                            $qqq->whereNotIn('status', ['completed', 'cancelled']);
                        });
                });
            });
    }

    /**
     * Announcements that belong on the "Archive" tab. Compared to Inbox,
     * Archive drops the Inbox recency/expiry filters — permanent and
     * expires_on_date rows are visible historically for as long as they
     * exist — but still enforces the "match ended → hide entirely" rule
     * for match_scoped rows, because MD bulletins are transient by
     * product design ("as soon as the match is finished then they must
     * go away").
     */
    public function scopeArchive($query)
    {
        return $query
            ->whereNull('retracted_at')
            ->whereNotNull('sent_at')
            ->where(function ($q) {
                $q->where('retention', '!=', AnnouncementRetention::MatchScoped->value)
                    ->orWhereHas('matchEvent', function ($qq) {
                        $qq->whereNotIn('status', ['completed', 'cancelled']);
                    });
            });
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
