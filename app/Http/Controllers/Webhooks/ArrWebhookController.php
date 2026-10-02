<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessArrWebhook;
use App\Models\WebhookEvent;
use App\Support\IntegrationSettings;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Single shared endpoint for Seerr, Sonarr, and Radarr webhooks — the
 * source is identified from the payload shape rather than the URL, since all
 * three notify the one webhook URL shown in Settings > Integrations.
 */
class ArrWebhookController extends Controller
{
    public function __invoke(Request $request, IntegrationSettings $settings, string $secret): Response
    {
        $configured = (string) $settings->get('webhooks.arr_secret', '');

        if ($configured === '' || ! hash_equals($configured, $secret)) {
            abort(404);
        }

        $event = WebhookEvent::create([
            'source' => $this->detectSource($request),
            'event' => $request->input('eventType') ?? $request->input('notification_type'),
            'payload' => $request->all(),
        ]);

        ProcessArrWebhook::dispatch($event);

        return response()->noContent();
    }

    private function detectSource(Request $request): string
    {
        return match (true) {
            $request->has('notification_type') => 'seerr',
            $request->has('series') => 'sonarr',
            $request->has('movie') => 'radarr',
            default => 'unknown',
        };
    }
}
