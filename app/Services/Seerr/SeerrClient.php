<?php

namespace App\Services\Seerr;

use App\Enums\SeerrRequestStatus;
use App\Models\Title;
use App\Services\MediaRequests\MediaRequestService;
use App\Support\IntegrationSettings;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

class SeerrClient implements MediaRequestService
{
    public function __construct(private IntegrationSettings $settings) {}

    public function testConnection(): bool
    {
        if (! $this->configured()) {
            return false;
        }

        try {
            return $this->get('/api/v1/status')->successful();
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @return array{request_id: int|null, seerr_status: SeerrRequestStatus|null}
     */
    public function requestMovie(Title $title): array
    {
        $response = $this->post('/api/v1/request', [
            'mediaType' => 'movie',
            'mediaId' => $title->tmdb_id,
        ]);

        return [
            'request_id' => $response->json('id'),
            'seerr_status' => SeerrRequestStatus::fromSeerr($response->json('status')),
        ];
    }

    /**
     * @param  array<int, int>  $seasonNumbers
     * @return array{request_id: int|null, seerr_status: SeerrRequestStatus|null}
     */
    public function requestShow(Title $title, array $seasonNumbers): array
    {
        $response = $this->post('/api/v1/request', [
            'mediaType' => 'tv',
            'mediaId' => $title->tmdb_id,
            'seasons' => array_values($seasonNumbers),
        ]);

        return [
            'request_id' => $response->json('id'),
            'seerr_status' => SeerrRequestStatus::fromSeerr($response->json('status')),
        ];
    }

    /**
     * Reads `/api/v1/movie/{tmdbId}` or `/api/v1/tv/{tmdbId}`, which Seerr always answers
     * (even for a title it doesn't know about yet) with a `mediaInfo` object that is null until
     * someone — this app or Seerr's own UI — has requested or added it.
     *
     * @return array{available: bool, request_id: int|null, seerr_status: SeerrRequestStatus|null, declined: bool}|null
     */
    public function mediaStatus(Title $title): ?array
    {
        if (! $this->configured() || ! $title->tmdb_id) {
            return null;
        }

        try {
            $endpoint = $title->isMovie() ? "/api/v1/movie/{$title->tmdb_id}" : "/api/v1/tv/{$title->tmdb_id}";
            $mediaInfo = $this->get($endpoint)->json('mediaInfo');
        } catch (SeerrException) {
            return null;
        }

        if (! $mediaInfo) {
            return null;
        }

        // Seerr media status: 1 unknown, 2 pending, 3 processing, 4 partially available, 5 available.
        $status = (int) data_get($mediaInfo, 'status');
        $latestRequest = collect(data_get($mediaInfo, 'requests', []))->last();
        $requestStatus = data_get($latestRequest, 'status');

        return [
            'available' => in_array($status, [4, 5], true),
            'request_id' => data_get($latestRequest, 'id') ?? data_get($mediaInfo, 'id'),
            'seerr_status' => $requestStatus !== null ? SeerrRequestStatus::fromSeerr((int) $requestStatus) : null,
            'declined' => (int) $requestStatus === 3,
        ];
    }

    protected function configured(): bool
    {
        return $this->settings->configured('seerr.url', 'seerr.api_key');
    }

    protected function get(string $endpoint): Response
    {
        try {
            return $this->client()->get($endpoint);
        } catch (ConnectionException $exception) {
            throw SeerrException::connectionFailed($endpoint, $exception->getMessage());
        } catch (RequestException $exception) {
            throw SeerrException::requestFailed($endpoint, $exception->response->status(), $exception->response->body());
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function post(string $endpoint, array $payload): Response
    {
        if (! $this->configured()) {
            throw SeerrException::notConfigured();
        }

        try {
            return $this->client()->post($endpoint, $payload)->throw();
        } catch (ConnectionException $exception) {
            throw SeerrException::connectionFailed($endpoint, $exception->getMessage());
        } catch (RequestException $exception) {
            throw SeerrException::requestFailed($endpoint, $exception->response->status(), $exception->response->body());
        }
    }

    protected function client(): PendingRequest
    {
        return Http::baseUrl(rtrim((string) $this->settings->get('seerr.url'), '/'))
            ->withHeader('X-Api-Key', (string) $this->settings->get('seerr.api_key'))
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
