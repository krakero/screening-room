<?php

namespace App\Support;

use App\Models\IntegrationSetting;

/**
 * Encrypted key/value store for integration credentials (Plex, Seerr, Sonarr, Radarr, Pushover, …).
 * Keys are dotted, e.g. "plex.url", "plex.token", "seerr.api_key".
 */
class IntegrationSettings
{
    /** @var array<string, mixed>|null */
    private ?array $cache = null;

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->all()[$key] ?? $default;
    }

    public function set(string $key, mixed $value): void
    {
        IntegrationSetting::updateOrCreate(['key' => $key], ['value' => $value]);

        $this->cache = null;
    }

    /**
     * @param  array<string, mixed>  $values
     */
    public function setMany(array $values): void
    {
        foreach ($values as $key => $value) {
            $this->set($key, $value);
        }
    }

    public function forget(string $key): void
    {
        IntegrationSetting::where('key', $key)->delete();

        $this->cache = null;
    }

    /**
     * True when every given key has a non-empty value.
     */
    public function configured(string ...$keys): bool
    {
        foreach ($keys as $key) {
            if (blank($this->get($key))) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return $this->cache ??= IntegrationSetting::all()->pluck('value', 'key')->all();
    }
}
