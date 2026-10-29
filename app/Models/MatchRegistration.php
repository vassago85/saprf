<?php

namespace App\Models;

use App\Services\SettingsService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class MatchRegistration extends Model
{
    protected $fillable = [
        'match_id',
        'user_id',
        'registered_by_user_id',
        'rifle_configuration_id',
        'division_id',
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
        'withdrawal_option',
        'penalty_platform_fee',
        'penalty_gateway_fee',
        'penalty_saprf_fee',
        'payment_status',
        'registration_status',
        'registered_at',
        'cancelled_at',
        'shot_count',
        'registration_source',
        'walk_in_note',
        'walk_in_confirmed_by',
        'walk_in_confirmed_at',
        'payment_inquiry_sent_at',
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
            'penalty_platform_fee' => 'decimal:2',
            'penalty_gateway_fee' => 'decimal:2',
            'penalty_saprf_fee' => 'decimal:2',
            'registered_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'shot_count' => 'integer',
            'walk_in_confirmed_at' => 'datetime',
            'payment_inquiry_sent_at' => 'datetime',
        ];
    }

    /**
     * A walk-in entry is one MD created retroactively while uploading
     * scores — the shooter shot the match but never registered through
     * the site. Range collected the entry fee directly; SAPRF+platform
     * are billed against the range's payout for this match (see
     * `md_net_amount` populated as a negative value on walk-in rows).
     */
    public function isWalkIn(): bool
    {
        return $this->registration_source === 'walk_in';
    }

    /**
     * How did the money for this entry (if any) reach the platform?
     *
     * Returned slug powers the Payment column on the match financials
     * report and payout statement so an R0 gateway row is explained
     * (manual, waived, walk-in) and card rows show the actual gateway.
     *
     *   - `card`         PayFast credit card
     *   - `instant_eft`  PayFast instant EFT
     *   - `card_or_eft`  PayFast completed, method not recorded
     *   - `manual`       Admin seeded, paid outside the gateway
     *   - `waived`       Comped by exco/MD
     *   - `walk_in`      Walk-in registered by MD on scoring day
     *   - `pending`      Not yet paid (any of unpaid, pending, failed, refunded)
     */
    public function paymentMethodSlug(): string
    {
        if ($this->isWalkIn()) {
            return 'walk_in';
        }

        if ($this->payment_status === 'waived') {
            return 'waived';
        }

        if ($this->payment_status !== 'paid') {
            return 'pending';
        }

        $payment = $this->payments()
            ->where('status', 'completed')
            ->latest('id')
            ->first();

        if (! $payment) {
            // Paid but no gateway payment — admin-seeded entry.
            return 'manual';
        }

        $raw = (array) ($payment->gateway_response ?? []);
        $method = strtolower((string) ($raw['payment_method'] ?? $raw['payment_type'] ?? ''));

        if (str_contains($method, 'cc') || str_contains($method, 'card')) {
            return 'card';
        }
        if (str_contains($method, 'eft') || str_contains($method, 'ozow') || str_contains($method, 'instant')) {
            return 'instant_eft';
        }

        return 'card_or_eft';
    }

    public function paymentMethodLabel(): string
    {
        return match ($this->paymentMethodSlug()) {
            'card' => 'Card',
            'instant_eft' => 'Instant EFT',
            'card_or_eft' => 'Card / EFT',
            'manual' => 'Manual',
            'waived' => 'Comp',
            'walk_in' => 'Walk-in',
            default => 'Pending',
        };
    }

    public function walkInConfirmer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'walk_in_confirmed_by');
    }

    /**
     * The entry-fee side of the row is still open — no PayFast success,
     * no waiver, and a positive fee was actually charged. Free entries
     * ({@see fee_amount} = 0) are not "outstanding".
     */
    public function hasOutstandingPayment(): bool
    {
        if (in_array($this->payment_status, ['paid', 'waived'], true)) {
            return false;
        }

        return (float) $this->fee_amount > 0;
    }

    /**
     * Unpaid fee on a match that has already reached its match day.
     * A future match stays payable, but it does not block other entries.
     *
     * @param  Builder<MatchRegistration>  $query
     * @return Builder<MatchRegistration>
     */
    public function scopeMatchDateReached(Builder $query): Builder
    {
        return $query->whereHas('match', function (Builder $match) {
            $match->whereDate('match_date', '<=', now()->toDateString());
        });
    }

    /**
     * Oldest non-cancelled entry for this shooter that still needs payment
     * on a match whose date has arrived. Portal entry for any other match
     * is blocked until that fee is settled. Unpaid entries for future
     * matches are left alone.
     */
    public static function firstOutstandingForUser(int $userId): ?self
    {
        return static::query()
            ->where('user_id', $userId)
            ->where('registration_status', '!=', 'cancelled')
            ->where('fee_amount', '>', 0)
            ->whereNotIn('payment_status', ['paid', 'waived'])
            ->matchDateReached()
            ->with('match:id,name,match_date')
            ->orderBy('registered_at')
            ->orderBy('id')
            ->first();
    }

    /**
     * Flash copy when {@see firstOutstandingForUser()} blocks a new entry.
     */
    public function outstandingBlocksNewEntryMessage(bool $isSelf): string
    {
        $matchName = $this->match?->name ?? 'another match';
        $who = $isSelf ? 'You still have' : ($this->shooter_name ?: 'This shooter').' still has';

        return "{$who} an unpaid entry for {$matchName}. Pay that fee before entering another match.";
    }

    /**
     * Money for this entry came in through the platform (PayFast or account
     * credit). Cancelling such a row credits the payer's account.
     */
    public function hasCompletedPlatformPayment(): bool
    {
        return $this->payments()->where('status', 'completed')->exists();
    }

    /**
     * The platform actually holds this entry fee. Cash, EFT at the range,
     * and a row simply marked paid do not — those must not be paid out
     * to the match director a second time.
     */
    public function settledOnPlatform(): bool
    {
        $completed = fn ($payment) => $payment->status === 'completed'
            && in_array($payment->gateway, ['payfast', 'account_credit'], true);

        if ($this->relationLoaded('payments')) {
            return $this->payments->contains($completed);
        }

        return $this->payments()
            ->where('status', 'completed')
            ->whereIn('gateway', ['payfast', 'account_credit'])
            ->exists();
    }

    public function hasIssuedCredit(): bool
    {
        return AccountCredit::query()->where('issued_for_registration_id', $this->id)->exists();
    }

    /**
     * Card payments plus any account credit redeemed against this entry,
     * less partial credits already given back (surcharge or base-fee
     * reductions) — i.e. what SAPRF actually holds for it.
     */
    public function amountPaidOnPlatform(): float
    {
        $cardPaid = (float) $this->payments()
            ->where('status', 'completed')
            ->where('gateway', '!=', 'account_credit')
            ->sum('amount');

        $creditRedeemed = abs((float) AccountCredit::query()
            ->where('applied_to_registration_id', $this->id)
            ->where('type', AccountCredit::TYPE_REDEMPTION)
            ->where('status', AccountCredit::STATUS_POSTED)
            ->sum('amount'));

        $paid = $cardPaid + $creditRedeemed;

        return $paid > 0 ? round(max(0, $paid - $this->adjustmentCreditIssued()), 2) : 0.0;
    }

    /**
     * Partial credits already returned on this entry (surcharge drop and
     * base-fee drop). A later withdrawal or cancellation must not pay
     * these amounts out a second time.
     */
    public function adjustmentCreditIssued(): float
    {
        return round($this->surchargeCreditIssued() + $this->feeAdjustmentCreditIssued(), 2);
    }

    /**
     * Surcharge credited back to the payer after the surcharge was lowered.
     */
    public function surchargeCreditIssued(): float
    {
        return round((float) AccountCredit::query()
            ->where('adjusted_registration_id', $this->id)
            ->where('type', AccountCredit::TYPE_SURCHARGE_ADJUSTMENT)
            ->where('status', AccountCredit::STATUS_POSTED)
            ->sum('amount'), 2);
    }

    /**
     * Base fee credited back to the payer after the entry fee was lowered
     * (for example a junior fee cut after the entry was already paid).
     */
    public function feeAdjustmentCreditIssued(): float
    {
        return round((float) AccountCredit::query()
            ->where('fee_adjusted_registration_id', $this->id)
            ->where('type', AccountCredit::TYPE_FEE_ADJUSTMENT)
            ->where('status', AccountCredit::STATUS_POSTED)
            ->sum('amount'), 2);
    }

    /**
     * Who receives withdrawal credit. Money that came in off the platform
     * (imported from the previous site, or confirmed as paid there) goes
     * back to the shooter, not whoever keyed the entry in.
     */
    public function withdrawalCreditHolderId(): ?int
    {
        return $this->amountPaidOnPlatform() > 0 ? $this->platformPayerId() : $this->user_id;
    }

    /**
     * Account the money goes back to: whoever completed the payment, then
     * the parent/sponsor who entered the shooter, then the shooter.
     */
    public function platformPayerId(): ?int
    {
        return $this->payments()
            ->where('status', 'completed')
            ->orderByDesc('id')
            ->value('user_id')
            ?? $this->registered_by_user_id
            ?? $this->user_id;
    }

    /**
     * Rate-limits the MD "send payment inquiry" button so a heavy click
     * (or a double-submit) doesn't hammer the same shooter twice in a
     * row. 24 hours is deliberately generous — the shooter has probably
     * not read the first email yet, and payment reconciliation is
     * inherently a slow, human back-and-forth.
     */
    public function canSendPaymentInquiry(): bool
    {
        if (! $this->hasOutstandingPayment()) {
            return false;
        }

        if ($this->payment_inquiry_sent_at === null) {
            return true;
        }

        return $this->payment_inquiry_sent_at->lt(now()->subHours(24));
    }

    public function feeCategoryLabel(): string
    {
        return match ($this->membership_fee_category) {
            'active_member' => 'Active Member',
            'lapsed_member' => 'Lapsed Member',
            'non_member' => 'Non-member',
            'club_entry' => 'Club entry',
            default => $this->membership_fee_category
                ? str_replace('_', ' ', $this->membership_fee_category)
                : '—',
        };
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

    /**
     * The account that created the entry (parent, sponsor, or self).
     * Null on classic self-entries created before the field existed.
     */
    public function registeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registered_by_user_id');
    }

    public function rifleConfiguration(): BelongsTo
    {
        return $this->belongsTo(RifleConfiguration::class);
    }

    public function division(): BelongsTo
    {
        return $this->belongsTo(Division::class);
    }

    public function ammoLoad(): BelongsTo
    {
        return $this->belongsTo(AmmoLoad::class);
    }

    // ── Editing Logic ──

    /**
     * May the shooter still change their entry details (division, rifle)?
     * Allowed while the entry is live and registration has not yet closed —
     * i.e. up to the registration close date, or the match date when no close
     * date is set.
     */
    /**
     * Staff may recategorise (and reprice) an entry only while no money
     * has been collected — changing a paid row would desync payouts.
     */
    public function canCorrectCategory(): bool
    {
        if ($this->registration_status === 'cancelled') {
            return false;
        }

        return ! in_array($this->payment_status, ['paid', 'waived'], true);
    }

    public function canEditEntry(): bool
    {
        if ($this->registration_status === 'cancelled') {
            return false;
        }

        $match = $this->match;

        if (! $match || in_array($match->status, ['completed', 'cancelled'], true)) {
            return false;
        }

        if ($match->registration_close_date) {
            return $match->registration_close_date->isFuture();
        }

        return $match->match_date ? $match->match_date->isFuture() : true;
    }

    // ── Withdrawal Logic ──

    public function isWithdrawable(): bool
    {
        return in_array($this->registration_status, ['pending', 'confirmed', 'waitlisted'])
            && $this->match?->status !== 'cancelled'
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

    /**
     * Replace the card-rate gateway estimate with the fee PayFast actually
     * deducted, and rebalance MD net so the row still adds up.
     */
    public function applyActualGatewayFee(float $gatewayFee): void
    {
        $gatewayFee = round(abs($gatewayFee), 2);

        $this->update([
            'gateway_fee' => $gatewayFee,
            'md_net_amount' => round(
                (float) $this->fee_amount
                - (float) $this->saprf_fee
                - (float) $this->platform_fee
                - (float) $this->surcharge_amount
                - $gatewayFee,
                2
            ),
        ]);
    }

    /**
     * What a member gets back when withdrawing, for both choices:
     *  - `credit`: the full amount paid, kept as account credit (no fee).
     *  - `refund`: cash back after the withdrawal fee (`admin_fee`), which is
     *    split developer first, then the original PayFast fee, then SAPRF.
     * Both are only offered before the withdrawal deadline. Entries paid off
     * the platform (`paid_offline`) can only take the entry fee as credit.
     *
     * @return array{refund: int|float, admin_fee: int|float, reason: string, credit?: float, split?: array{platform: float, gateway: float, saprf: float}}
     */
    public function calculateRefund(): array
    {
        $settings = app(SettingsService::class);
        $adminFee = (float) $settings->get('withdrawal_admin_fee', 100);
        $fee = (float) $this->fee_amount;

        // Free entry — no money changed hands, so no refund and no admin fee.
        // Return a distinct reason so views/messages can render coherent copy
        // instead of the deadline-based branches (which would nonsensically
        // charge a R100 admin fee against a R0 entry).
        if ($fee <= 0) {
            return [
                'refund' => 0,
                'admin_fee' => 0,
                'reason' => 'free_entry',
            ];
        }

        // Nothing was ever collected (card failed, gateway cancelled, or the
        // member simply never completed checkout). Withdrawing must not quote
        // a refund or an admin fee against money that never arrived.
        // `waived` covers shooters who confirmed paying on the previous site.
        if (! in_array($this->payment_status, ['paid', 'waived'], true)) {
            return [
                'refund' => 0,
                'admin_fee' => 0,
                'reason' => 'unpaid',
            ];
        }

        if (! $this->isBeforeDeadline()) {
            return [
                'refund' => 0,
                'admin_fee' => $fee,
                'reason' => 'past_deadline',
            ];
        }

        // Paid, but not through this platform (imported from the previous
        // site, or collected by the MD). No cash refund — the entry fee can
        // only be kept as credit, and no PayFast fee travels with it.
        $paid = $this->amountPaidOnPlatform();
        if ($paid <= 0) {
            return [
                'refund' => 0,
                'admin_fee' => 0,
                'credit' => round($fee, 2),
                'reason' => 'paid_offline',
            ];
        }

        $penalty = round(min($adminFee, $paid), 2);

        return [
            'refund' => round($paid - $penalty, 2),
            'admin_fee' => $penalty,
            'credit' => $paid,
            'split' => $this->penaltySplit($penalty),
            'reason' => 'before_deadline',
        ];
    }

    /**
     * @return array{platform: float, gateway: float, saprf: float}
     */
    public function penaltySplit(float $penalty): array
    {
        $developerShare = (float) app(SettingsService::class)->get('withdrawal_developer_fee', 50);

        $platform = round(min(max($developerShare, 0), $penalty), 2);
        $gateway = round(min((float) $this->gateway_fee, $penalty - $platform), 2);

        return [
            'platform' => $platform,
            'gateway' => $gateway,
            'saprf' => round($penalty - $platform - $gateway, 2),
        ];
    }

    /**
     * Confirmed entries that no longer need payment may join the match
     * WhatsApp group. Waitlisted and outstanding-fee rows stay hidden.
     */
    public function canAccessWhatsappInvite(): bool
    {
        if (! $this->match?->hasWhatsappInvite()) {
            return false;
        }

        if ($this->registration_status !== 'confirmed') {
            return false;
        }

        if (in_array($this->payment_status, ['paid', 'waived'], true)) {
            return true;
        }

        return (float) $this->fee_amount <= 0;
    }

    public function whatsappInviteUrl(): ?string
    {
        return $this->canAccessWhatsappInvite() ? $this->match?->whatsappInviteUrl() : null;
    }

    /**
     * URL for the payment-success page: the shooter has just paid (or is
     * about to, once the ITN lands). Waitlisted entries still do not get it.
     */
    public function whatsappInviteUrlAfterPayment(): ?string
    {
        if ($this->registration_status === 'waitlisted') {
            return null;
        }

        return $this->match?->whatsappInviteUrl();
    }
}
