<?php

namespace App\Services\MdbList;

use App\Support\IntegrationSettings;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class MdbListClient
{
    public function __construct(private readonly IntegrationSettings $settings) {}

    /**
     * True when the configured API key is accepted by MDBList's own account endpoint.
     */
    public function testConnection(): bool
    {
        if (! $this->settings->configured('mdblist.api_key')) {
            return false;
        }

        try {
            $response = $this->client()->get('/user');
        } catch (ConnectionException) {
            return false;
        }

        return $response->successful();
    }

    /**
     * Raw MDBList media response, keyed by provider (imdb|tmdb) and media type (movie|show).
     *
     * @return array<string, mixed>
     */
    public function lookup(string $provider, string $mediaType, string $mediaId): array
    {
        if (! $this->settings->configured('mdblist.api_key')) {
            throw MdbListException::notConfigured();
        }

        try {
            $response = $this->client()->get("/{$provider}/{$mediaType}/{$mediaId}/");
        } catch (ConnectionException $exception) {
            throw MdbListException::connectionFailed($exception->getMessage());
        }

        if ($response->status() === 429) {
            Log::warning('MDBList rate limited the request; keeping existing cached ratings.', [
                'provider' => $provider,
                'media_type' => $mediaType,
                'media_id' => $mediaId,
            ]);

            throw MdbListException::rateLimited();
        }

        if (! $response->successful()) {
            throw MdbListException::requestFailed($response->status(), $response->body());
        }

        return $response->json() ?? [];
    }

    private function client(): PendingRequest
    {
        return Http::baseUrl('https://api.mdblist.com')
            ->timeout(10)
            ->connectTimeout(5)
            ->retry(3, 100, fn (Throwable $exception): bool => $exception instanceof ConnectionException, throw: false)
            ->withQueryParameters(['apikey' => $this->settings->get('mdblist.api_key')]);
    }
}
