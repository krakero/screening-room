<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessPlexWebhook;
use App\Models\WebhookEvent;
use App\Support\IntegrationSettings;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class PlexWebhookController extends Controller
{
    public function __invoke(Request $request, IntegrationSettings $settings, string $secret): Response
    {
        $configuredSecret = (string) $settings->get('plex.webhook_secret', '');

        if ($configuredSecret === '' || ! hash_equals($configuredSecret, $secret)) {
            abort(404);
        }

        $payload = json_decode((string) $request->input('payload', '{}'), true) ?? [];

        $webhookEvent = WebhookEvent::create([
            'source' => 'plex',
            'event' => $payload['event'] ?? null,
            'payload' => $payload,
        ]);

        ProcessPlexWebhook::dispatch($webhookEvent);

        return response()->noContent();
    }
}
