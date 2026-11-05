<?php

namespace App\Services\Api;

use App\Exceptions\ApiUnavailable;
use App\Exceptions\Unauthenticated;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Native\Mobile\Facades\SecureStorage;

class ScreeningRoomApi
{
    private const TIMEOUT = 30;

    /**
     * @throws ApiUnavailable
     * @throws Unauthenticated
     */
    public function get(string $path, array $data = []): Response
    {
        return $this->request('GET', $path, $data);
    }

    /**
     * @throws ApiUnavailable
     * @throws Unauthenticated
     */
    public function post(string $path, array $data = []): Response
    {
        return $this->request('POST', $path, $data);
    }

    /**
     * @throws ApiUnavailable
     * @throws Unauthenticated
     */
    public function put(string $path, array $data = []): Response
    {
        return $this->request('PUT', $path, $data);
    }

    /**
     * @throws ApiUnavailable
     * @throws Unauthenticated
     */
    public function delete(string $path, array $data = []): Response
    {
        return $this->request('DELETE', $path, $data);
    }

    /**
     * @throws ApiUnavailable
     * @throws Unauthenticated
     */
    private function request(string $method, string $path, array $data = []): Response
    {
        $baseUrl = SecureStorage::get('server_url');
        $token = SecureStorage::get('api_token');

        if (! $baseUrl) {
            throw new ApiUnavailable('Server URL not configured');
        }

        try {
            $http = Http::acceptJson()
                ->timeout(self::TIMEOUT)
                ->when($token, fn ($h) => $h->withToken($token));

            $response = match ($method) {
                'GET' => $http->get("{$baseUrl}/api/v1/{$path}", $data),
                'POST' => $http->post("{$baseUrl}/api/v1/{$path}", $data),
                'PUT' => $http->put("{$baseUrl}/api/v1/{$path}", $data),
                'DELETE' => $http->delete("{$baseUrl}/api/v1/{$path}", $data),
                default => $http->send($method, "{$baseUrl}/api/v1/{$path}", $data),
            };

            if ($response->status() === 401) {
                SecureStorage::delete('api_token');
                throw new Unauthenticated('Session expired. Please sign in again.');
            }

            // Server errors surface the same way as an unreachable server, so every screen's
            // existing ApiUnavailable handling shows its error/retry state. 4xx responses
            // (e.g. 422 validation) are returned for the caller to read.
            if ($response->serverError()) {
                throw new ApiUnavailable('The server had a problem. Please try again.');
            }

            return $response;
        } catch (ConnectionException $e) {
            throw new ApiUnavailable('Unable to connect to server. Please check your connection.', 0, $e);
        }
    }
}
