<?php

namespace App\Services\Qbittorrent;

use App\Enums\TorrentState;
use App\Support\IntegrationSettings;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Read-only client for the qBittorrent WebUI API v2.
 */
class QbittorrentClient
{
    private const SID_CACHE_KEY = 'qbittorrent:sid';

    private const SID_TTL_MINUTES = 50;

    public function __construct(private IntegrationSettings $settings) {}

    public function configured(): bool
    {
        return $this->settings->configured('qbittorrent.url');
    }

    public function webUiUrl(): ?string
    {
        return $this->configured() ? $this->baseUrl() : null;
    }

    public function testConnection(): bool
    {
        if (! $this->configured()) {
            return false;
        }

        try {
            $this->version();

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    public function version(): string
    {
        return trim($this->get('/api/v2/app/version')->body());
    }

    /**
     * @return Collection<int, Torrent>
     */
    public function torrents(): Collection
    {
        return collect($this->get('/api/v2/torrents/info')->json() ?? [])
            ->map(fn (array $row): Torrent => Torrent::fromApi($row))
            ->sortBy([
                fn (Torrent $a, Torrent $b): int => ($b->state === TorrentState::Downloading) <=> ($a->state === TorrentState::Downloading),
                fn (Torrent $a, Torrent $b): int => $b->addedOn <=> $a->addedOn,
            ])
            ->values();
    }

    protected function get(string $endpoint): Response
    {
        if (! $this->configured()) {
            throw QbittorrentException::notConfigured();
        }

        $response = $this->send($endpoint);

        if ($response->status() === 403 && $this->hasCredentials()) {
            Cache::forget(self::SID_CACHE_KEY);
            $response = $this->send($endpoint);
        }

        if (! $response->successful()) {
            throw QbittorrentException::requestFailed($endpoint, $response->status(), $response->body());
        }

        return $response;
    }

    protected function send(string $endpoint): Response
    {
        $request = $this->client();

        if ($this->hasCredentials()) {
            $request = $request->withCookies(['SID' => $this->sid()], $this->host());
        }

        try {
            return $request->get($endpoint);
        } catch (ConnectionException $exception) {
            throw QbittorrentException::connectionFailed($endpoint, $exception->getMessage());
        }
    }

    protected function sid(): string
    {
        return Cache::remember(self::SID_CACHE_KEY, now()->addMinutes(self::SID_TTL_MINUTES), fn (): string => $this->login());
    }

    protected function login(): string
    {
        $endpoint = '/api/v2/auth/login';

        try {
            $response = $this->client()
                ->withHeaders(['Referer' => $this->baseUrl(), 'Origin' => $this->baseUrl()])
                ->asForm()
                ->post($endpoint, [
                    'username' => (string) $this->settings->get('qbittorrent.username'),
                    'password' => (string) $this->settings->get('qbittorrent.password'),
                ]);
        } catch (ConnectionException $exception) {
            throw QbittorrentException::connectionFailed($endpoint, $exception->getMessage());
        }

        if (! $response->successful()) {
            throw QbittorrentException::requestFailed($endpoint, $response->status(), $response->body());
        }

        $sid = $response->cookies()->getCookieByName('SID')?->getValue();

        if (trim($response->body()) === 'Fails.' || blank($sid)) {
            throw QbittorrentException::invalidCredentials();
        }

        return $sid;
    }

    protected function hasCredentials(): bool
    {
        return $this->settings->configured('qbittorrent.username');
    }

    protected function baseUrl(): string
    {
        return rtrim((string) $this->settings->get('qbittorrent.url'), '/');
    }

    protected function host(): string
    {
        return (string) parse_url($this->baseUrl(), PHP_URL_HOST);
    }

    protected function client(): PendingRequest
    {
        return Http::baseUrl($this->baseUrl())
            ->withHeader('Referer', $this->baseUrl())
            ->timeout(10)
            ->connectTimeout(5);
    }
}
