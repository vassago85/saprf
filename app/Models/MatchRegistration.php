<?php

namespace App\Models;

use App\Services\SettingsService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class MatchRegistration extends Model
{
    protected $fillable = [
        'match_id',
        'user_id',
        'rifle_configuration_id',
        'ammo_load_id',
        'shooter_name',
        'email',
        'phone',
        'membership_fee_category',
        'fee_amount',
        'surcharge_amount',
        'saprf_fee',
        'platform_fee',
        'gateway_fee',
        'md_net_amount',
        'fee_override_reason',
        'refund_amount',
        'admin_fee_charged',
        'cancellation_reason',
        'payment_status',
        'registration_status',
        'registered_at',
        'cancelled_at',
    ];

    protected function casts(): array
    {
        return [
            'fee_amount' => 'decimal:2',
            'surcharge_amount' => 'decimal:2',
            'saprf_fee' => 'decimal:2',
            'platform_fee' => 'decimal:2',
            'gateway_fee' => 'decimal:2',
            'md_net_amount' => 'decimal:2',
            'refund_amount' => 'decimal:2',
            'admin_fee_charged' => 'decimal:2',
            'registered_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    // ── Relationships ──

    public function match(): BelongsTo
    {
        return $this->belongsTo(MatchEvent::class, 'match_id');
    }

    public function payments(): MorphMany
    {
        return $this->morphMany(Payment::class, 'payable');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function rifleConfiguration(): BelongsTo
    {
        return $this->belongsTo(RifleConfiguration::class);
    }

    public function ammoLoad(): BelongsTo
    {
        return $this->belongsTo(AmmoLoad::class);
    }

    // ── Withdrawal Logic ──

    public function isWithdrawable(): bool
    {
        return in_array($this->registration_status, ['pending', 'confirmed', 'waitlisted'])
            && $this->match?->match_date?->isFuture();
    }

    public function isBeforeDeadline(): bool
    {
        if (! $this->match?->match_date) {
            return false;
        }

        $hours = (int) app(SettingsService::class)->get('withdrawal_deadline_hours', 72);
        $deadline = $this->match->match_date->copy()->subHours($hours);

        return now()->lt($deadline);
    }

    public function withdrawalDeadline(): ?Carbon
    {
        if (! $this->match?->match_date) {
            return null;
        }

        $hours = (int) app(SettingsService::class)->get('withdrawal_deadline_hours', 72);

        return $this->match->match_date->copy()->subHours($hours);
    }

    public function calculateRefund(): array
    {
        $settings = app(SettingsService::class);
        $adminFee = (float) $settings->get('withdrawal_admin_fee', 100);
        $fee = (float) $this->fee_amount;

        if (! $this->isBeforeDeadline()) {
            return [
                'refund' => 0,
                'admin_fee' => $fee,
                'reason' => 'past_deadline',
            ];
        }

        $refund = max(0, $fee - $adminFee);

        return [
            'refund' => $refund,
            'admin_fee' => $adminFee,
            'reason' => 'before_deadline',
        ];
    }
}
