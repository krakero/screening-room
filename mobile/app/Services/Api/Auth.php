<?php

namespace App\Services\Api;

use App\Exceptions\ApiUnavailable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Native\Mobile\Facades\SecureStorage;

class Auth
{
    /**
     * @return array{success: bool, error?: string}
     *
     * @throws ApiUnavailable
     */
    public function signIn(string $serverUrl, string $email, string $password): array
    {
        return $this->requestToken($serverUrl, 'auth/token', [
            'email' => $email,
            'password' => $password,
        ], errorField: 'email', fallbackError: 'Invalid credentials');
    }

    /**
     * Exchange a completed Plex PIN login's token for a Screening Room token. The server only
     * accepts it for an existing user already linked to that Plex account.
     *
     * @return array{success: bool, error?: string}
     *
     * @throws ApiUnavailable
     */
    public function signInWithPlex(string $serverUrl, string $plexToken): array
    {
        return $this->requestToken($serverUrl, 'auth/plex', [
            'plex_token' => $plexToken,
        ], errorField: 'plex_token', fallbackError: 'This Plex account isn\'t linked to Screening Room.');
    }

    /**
     * Confirm the URL points at a Screening Room server, so a typo fails before the Plex login opens.
     *
     * @return array{success: bool, error?: string}
     *
     * @throws ApiUnavailable
     */
    public function checkServer(string $serverUrl): array
    {
        return $this->validateServer($this->normalizeUrl($serverUrl));
    }

    /**
     * @param  array<string, string>  $credentials
     * @return array{success: bool, error?: string}
     *
     * @throws ApiUnavailable
     */
    private function requestToken(string $serverUrl, string $endpoint, array $credentials, string $errorField, string $fallbackError): array
    {
        $normalizedUrl = $this->normalizeUrl($serverUrl);

        $validation = $this->validateServer($normalizedUrl);
        if (! $validation['success']) {
            return $validation;
        }

        try {
            $loginResponse = Http::acceptJson()
                ->timeout(10)
                ->post("{$normalizedUrl}/api/v1/{$endpoint}", [
                    ...$credentials,
                    'device_name' => $this->getDeviceName(),
                ]);

            if ($loginResponse->status() === 422) {
                return ['success' => false, 'error' => $loginResponse->json("errors.{$errorField}.0") ?? $fallbackError];
            }

            if (! $loginResponse->successful()) {
                return ['success' => false, 'error' => 'Sign in failed. Please try again.'];
            }

            $token = $loginResponse->json('token');

            if (! $token) {
                return ['success' => false, 'error' => 'No token returned from server'];
            }

            SecureStorage::set('server_url', $normalizedUrl);
            SecureStorage::set('api_token', $token);

            return ['success' => true];
        } catch (ConnectionException) {
            throw new ApiUnavailable('Unable to connect to server. Please check your connection.');
        }
    }

    public function signOut(): void
    {
        $token = SecureStorage::get('api_token');
        $serverUrl = SecureStorage::get('server_url');

        if ($token && $serverUrl) {
            try {
                Http::acceptJson()
                    ->withToken($token)
                    ->timeout(5)
                    ->delete("{$serverUrl}/api/v1/auth/token");
            } catch (ConnectionException) {
                // Ignore connection errors on sign out
            }
        }

        SecureStorage::delete('api_token');
        SecureStorage::delete('server_url');
    }

    public function isSignedIn(): bool
    {
        return SecureStorage::get('api_token') !== null;
    }

    /**
     * @return array{success: bool, error?: string}
     *
     * @throws ApiUnavailable
     */
    private function validateServer(string $normalizedUrl): array
    {
        try {
            $validationResponse = Http::acceptJson()
                ->timeout(10)
                ->get("{$normalizedUrl}/api/v1");

            if (! $validationResponse->successful() || $validationResponse->json('status') !== 'ok') {
                return ['success' => false, 'error' => 'Invalid server URL or server not configured'];
            }

            return ['success' => true];
        } catch (ConnectionException) {
            throw new ApiUnavailable('Unable to connect to server. Please check the URL and your connection.');
        }
    }

    private function normalizeUrl(string $url): string
    {
        $url = trim($url);

        // Add https:// if no scheme
        if (! preg_match('#^https?://#i', $url)) {
            $url = 'https://'.$url;
        }

        // Remove trailing slashes
        return rtrim($url, '/');
    }

    private function getDeviceName(): string
    {
        // TODO: Detect platform (iOS vs Android)
        return 'Screening Room iOS';
    }
}
