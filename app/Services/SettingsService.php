<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Facades\Cache;

class SettingsService
{
    private const CACHE_KEY = 'saprf_settings';

    private const CACHE_TTL = 3600;

    public const NATIONAL_SERIES_2027_PUBLISHED = 'national_series_2027_published';

    /**
     * After a decision reaches 10 reactions: `public` shows the split to
     * everyone; `after_reaction` hides it until the viewer has reacted.
     */
    public const DECISION_REACTION_VISIBILITY = 'decision_reaction_visibility';

    /**
     * Days after publish that members may react. Default 7.
     */
    public const DECISION_REACTION_WINDOW_DAYS = 'decision_reaction_window_days';

    /**
     * Phase 4.7: whether non-member scores are eligible for podium /
     * division-winner callouts on the public match page. Default is false
     * so a casual non-member walk-in doesn't appear "above" a paid-up
     * provincial on the overview card. Admin opt-in via Site Settings.
     */
    public const SHOW_NON_MEMBERS_ON_PODIUM = 'show_non_members_on_podium';

    public function get(string $key, mixed $default = null): mixed
    {
        $settings = $this->all();

        return $settings[$key] ?? $default;
    }

    public function set(string $key, mixed $value, ?string $description = null): void
    {
        Setting::updateOrCreate(
            ['key' => $key],
            ['value' => $value, 'description' => $description ?? $key],
        );

        $this->clearCache();
    }

    public function all(): array
    {
        return Cache::remember(self::CACHE_KEY, self::CACHE_TTL, function () {
            return Setting::pluck('value', 'key')->toArray();
        });
    }

    public function clearCache(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * Dedicated ExCo inbox from Site Settings, or null when unset / invalid.
     */
    public function excoEmail(): ?string
    {
        return $this->normalizedEmail('exco_email');
    }

    /**
     * Dedicated owner inbox from Site Settings, or null when unset / invalid.
     */
    public function ownerEmail(): ?string
    {
        return $this->normalizedEmail('owner_email');
    }

    /**
     * Dedicated secretary inbox from Site Settings, or null when unset / invalid.
     */
    public function secretaryEmail(): ?string
    {
        return $this->normalizedEmail('secretary_email');
    }

    /**
     * Cash refunds of cancellation credit. Off until finance is ready to pay them.
     */
    public function cancellationRefundsEnabled(): bool
    {
        return (string) $this->get('cancellation_refunds_enabled', '0') === '1';
    }

    /**
     * The 2027 national series rules document stays parked (owner/admin
     * preview only, off /documents and search) until SAPRF announces it.
     * Flipped by `php artisan saprf:national-series-2027 publish`.
     */
    public function nationalSeries2027Published(): bool
    {
        return (string) $this->get(self::NATIONAL_SERIES_2027_PUBLISHED, '0') === '1';
    }

    /**
     * Address members should reply to. Secretary only — never ExCo or
     * owner. admin@precisionrifle.co.za forwards to the whole ExCo, so
     * falling back there would put every member reply on that list.
     */
    public function replyToEmail(): ?string
    {
        return $this->secretaryEmail();
    }

    private function normalizedEmail(string $key): ?string
    {
        $email = trim((string) $this->get($key, ''));

        return $email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)
            ? $email
            : null;
    }
}
