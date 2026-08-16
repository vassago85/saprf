<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Facades\Cache;

class SettingsService
{
    private const CACHE_KEY = 'saprf_settings';
    private const CACHE_TTL = 3600;

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
     * Address members should reply to. Owner inbox first, then ExCo.
     */
    public function replyToEmail(): ?string
    {
        return $this->ownerEmail() ?? $this->excoEmail();
    }

    private function normalizedEmail(string $key): ?string
    {
        $email = trim((string) $this->get($key, ''));

        return $email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)
            ? $email
            : null;
    }
}
