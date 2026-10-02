<?php

namespace App\Concerns;

use App\Support\IntegrationSettings;
use Illuminate\Support\Str;

trait ManagesWebhookSecret
{
    /**
     * Return the webhook URL for the given secret key, generating the secret on first use.
     */
    protected function ensureWebhookUrl(IntegrationSettings $settings, string $key, string $path): string
    {
        $secret = $settings->get($key);

        if (blank($secret)) {
            $secret = Str::random(40);
            $settings->set($key, $secret);
        }

        return url("/webhooks/{$path}/{$secret}");
    }

    /**
     * Rotate the secret behind a webhook URL and return the new URL.
     */
    protected function regenerateWebhookUrl(IntegrationSettings $settings, string $key, string $path): string
    {
        $settings->set($key, $secret = Str::random(40));

        return url("/webhooks/{$path}/{$secret}");
    }
}
