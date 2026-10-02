<?php

namespace App\Services\Pushover;

use App\Support\IntegrationSettings;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Throwable;

class PushoverClient
{
    public function __construct(private readonly IntegrationSettings $settings) {}

    /**
     * True when the configured user/app keys are valid, per Pushover's validate endpoint.
     */
    public function testConnection(): bool
    {
        if (! $this->settings->configured('pushover.user_key', 'pushover.app_token')) {
            return false;
        }

        try {
            $response = $this->client()->asForm()->post('/1/users/validate.json', [
                'token' => $this->settings->get('pushover.app_token'),
                'user' => $this->settings->get('pushover.user_key'),
            ]);
        } catch (ConnectionException) {
            return false;
        }

        return $response->successful() && $response->json('status') === 1;
    }

    public function send(string $title, string $message, ?string $url = null): void
    {
        try {
            $response = $this->client()->asForm()->post('/1/messages.json', array_filter([
                'token' => $this->settings->get('pushover.app_token'),
                'user' => $this->settings->get('pushover.user_key'),
                'title' => $title,
                'message' => $message,
                'url' => $url,
            ]));
        } catch (ConnectionException $exception) {
            throw PushoverException::connectionFailed($exception->getMessage());
        }

        if (! $response->successful()) {
            throw PushoverException::requestFailed($response->status(), $response->body());
        }
    }

    private function client(): PendingRequest
    {
        return Http::baseUrl('https://api.pushover.net')
            ->timeout(10)
            ->connectTimeout(5)
            ->retry(3, 100, fn (Throwable $exception): bool => $exception instanceof ConnectionException, throw: false);
    }
}
