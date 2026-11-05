<?php

namespace App\Services\Plex;

use App\Exceptions\ApiUnavailable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Native\Mobile\Facades\SecureStorage;

class PlexPinAuth
{
    private const PLEX_TV = 'https://plex.tv';

    private const TIMEOUT_SECONDS = 10;

    /**
     * Start a "Sign in with Plex" PIN flow.
     *
     * Plex ties the PIN to the client identifier that created it, so the caller must pass
     * the returned `client_id` back to check() rather than relying on it being re-read.
     *
     * @return array{id: int, url: string, client_id: string}
     *
     * @throws ApiUnavailable
     */
    public function start(): array
    {
        $clientId = $this->clientIdentifier();

        try {
            $response = Http::baseUrl(self::PLEX_TV)
                ->withHeaders($this->clientHeaders($clientId))
                ->acceptJson()
                ->timeout(self::TIMEOUT_SECONDS)
                ->post('/api/v2/pins', ['strong' => 'true']);

            if (! $response->successful()) {
                Log::warning('Plex PIN create failed', ['status' => $response->status(), 'body' => mb_substr($response->body(), 0, 500)]);
                throw new ApiUnavailable('Couldn\'t reach Plex. Please try again.');
            }

            $id = (int) $response->json('id', 0);
            $code = (string) $response->json('code', '');

            if ($id === 0 || $code === '') {
                throw new ApiUnavailable('Couldn\'t reach Plex. Please try again.');
            }

            return [
                'id' => $id,
                'url' => $this->authUrl($code, $clientId),
                'client_id' => $clientId,
            ];
        } catch (ConnectionException $e) {
            Log::warning('Plex request failed', ['error' => $e->getMessage()]);

            throw new ApiUnavailable('Couldn\'t reach Plex. Please try again.');
        }
    }

    /**
     * Check if the user has completed Plex sign-in. Returns the Plex token if complete, null otherwise.
     *
     * @throws ApiUnavailable
     */
    public function check(int $id, ?string $clientId = null): ?string
    {
        try {
            $response = Http::baseUrl(self::PLEX_TV)
                ->withHeaders($this->clientHeaders($clientId ?? $this->clientIdentifier()))
                ->acceptJson()
                ->timeout(self::TIMEOUT_SECONDS)
                ->get("/api/v2/pins/{$id}");

            if (! $response->successful()) {
                Log::warning('Plex PIN check failed', ['id' => $id, 'status' => $response->status(), 'body' => mb_substr($response->body(), 0, 500)]);
                throw new ApiUnavailable('Couldn\'t reach Plex. Please try again.');
            }

            $token = $response->json('authToken');

            return is_string($token) && $token !== '' ? $token : null;
        } catch (ConnectionException $e) {
            Log::warning('Plex request failed', ['error' => $e->getMessage()]);

            throw new ApiUnavailable('Couldn\'t reach Plex. Please try again.');
        }
    }

    /**
     * Get or generate a stable client identifier for Plex API requests.
     */
    private function clientIdentifier(): string
    {
        $identifier = SecureStorage::get('plex_client_id');

        if (is_string($identifier) && $identifier !== '') {
            return $identifier;
        }

        $identifier = (string) Str::uuid();
        SecureStorage::set('plex_client_id', $identifier);

        return $identifier;
    }

    /**
     * Build the Plex auth URL for the user to sign in.
     */
    private function authUrl(string $code, string $clientId): string
    {
        return 'https://app.plex.tv/auth#?'.http_build_query([
            'clientID' => $clientId,
            'code' => $code,
            'context[device][product]' => 'Screening Room',
        ]);
    }

    /**
     * @return array<string, string>
     */
    private function clientHeaders(string $clientId): array
    {
        return [
            'X-Plex-Client-Identifier' => $clientId,
            'X-Plex-Product' => 'Screening Room',
            'X-Plex-Version' => '1.0',
        ];
    }
}
