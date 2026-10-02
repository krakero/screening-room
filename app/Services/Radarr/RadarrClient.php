<?php

namespace App\Services\Radarr;

use App\Support\IntegrationSettings;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Read-only client. The app never asks Radarr to add movies — only reads
 * status back for the reconcile job, since Seerr owns requesting.
 */
class RadarrClient
{
    public function __construct(private IntegrationSettings $settings) {}

    public function testConnection(): bool
    {
        if (! $this->configured()) {
            return false;
        }

        try {
            return $this->get('/api/v3/system/status')->successful();
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    public function movieByTmdbId(int $tmdbId): ?array
    {
        if (! $this->configured()) {
            return null;
        }

        $results = $this->get('/api/v3/movie', ['tmdbId' => $tmdbId])->json();

        return $results[0] ?? null;
    }

    protected function configured(): bool
    {
        return $this->settings->configured('radarr.url', 'radarr.api_key');
    }

    /**
     * @param  array<string, mixed>  $query
     */
    protected function get(string $endpoint, array $query = []): Response
    {
        try {
            return $this->client()->get($endpoint, $query);
        } catch (ConnectionException $exception) {
            throw RadarrException::connectionFailed($endpoint, $exception->getMessage());
        } catch (RequestException $exception) {
            throw RadarrException::requestFailed($endpoint, $exception->response->status(), $exception->response->body());
        }
    }

    protected function client(): PendingRequest
    {
        return Http::baseUrl(rtrim((string) $this->settings->get('radarr.url'), '/'))
            ->withHeader('X-Api-Key', (string) $this->settings->get('radarr.api_key'))
            ->acceptJson()
            ->timeout(10)
            ->connectTimeout(5)
            ->retry(3, 100, function (Throwable $exception): bool {
                if ($exception instanceof ConnectionException) {
                    return true;
                }

                if ($exception instanceof RequestException) {
                    $status = $exception->response->status();

                    return $status === 429 || $status >= 500;
                }

                return false;
            });
    }
}
