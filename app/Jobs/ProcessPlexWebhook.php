<?php

namespace App\Jobs;

use App\Actions\Plex\RecordScrobble;
use App\Actions\Plex\UpsertPlexAvailabilityFromWebhook;
use App\Models\WebhookEvent;
use App\Services\Plex\PlexClient;
use App\Support\IntegrationSettings;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;
use Throwable;

class ProcessPlexWebhook implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly WebhookEvent $webhookEvent,
    ) {}

    public function handle(
        PlexClient $plex,
        RecordScrobble $recordScrobble,
        UpsertPlexAvailabilityFromWebhook $upsertPlexAvailability,
        IntegrationSettings $settings,
    ): void {
        $payload = $this->webhookEvent->payload;
        $event = $payload['event'] ?? null;
        $metadata = $payload['Metadata'] ?? [];
        $type = $metadata['type'] ?? null;

        try {
            $parsed = in_array($type, ['movie', 'episode'], true)
                ? PlexClient::guidsFromMetadata($metadata)
                : ['guids' => [], 'show_guids' => [], 'season_number' => null, 'episode_number' => null];

            $guids = $parsed['guids'];

            // The webhook payload should always carry the item's own Guid(s). This is only an
            // optional, best-effort enhancement for payloads that don't (very old agents), and
            // only runs when a token is configured — it must never be required for a webhook-only setup.
            if ($guids === [] && $parsed['show_guids'] === [] && isset($metadata['ratingKey']) && $settings->configured('plex.url', 'plex.token')) {
                try {
                    $guids = $plex->metadataGuids((string) $metadata['ratingKey']);
                } catch (Throwable) {
                    // best-effort only; fall through with empty guids.
                }
            }

            // Any movie/episode event that carries a ratingKey (media.scrobble, library.new,
            // media.on-deck, …) tells us this item is on Plex right now — cache that against the
            // matching local Title/Episode straight from the payload, no extra API call needed.
            if (isset($metadata['ratingKey']) && in_array($type, ['movie', 'episode'], true)) {
                $upsertPlexAvailability->handle(
                    $type,
                    (string) $metadata['ratingKey'],
                    $payload['Server']['uuid'] ?? null,
                    $guids,
                    $parsed['show_guids'],
                    $metadata['parentIndex'] ?? $parsed['season_number'],
                    $metadata['index'] ?? $parsed['episode_number'],
                );
            }

            if ($event !== 'media.scrobble') {
                $this->webhookEvent->markProcessed();

                return;
            }

            $result = $recordScrobble->attempt([
                'account_id' => $payload['Account']['id'] ?? null,
                'account_username' => $payload['Account']['title'] ?? null,
                'rating_key' => (string) ($metadata['ratingKey'] ?? ''),
                'viewed_at' => isset($metadata['viewedAt'])
                    ? Carbon::createFromTimestamp((int) $metadata['viewedAt'])
                    : Carbon::now(),
                'type' => $type,
                'title' => $metadata['title'] ?? '',
                'grandparent_title' => $metadata['grandparentTitle'] ?? null,
                'season_number' => $metadata['parentIndex'] ?? $parsed['season_number'],
                'episode_number' => $metadata['index'] ?? $parsed['episode_number'],
                'guids' => $guids,
                'show_guids' => $parsed['show_guids'],
            ]);

            if ($result['outcome'] === 'unmatched') {
                $label = filled($metadata['grandparentTitle'] ?? null)
                    ? "{$metadata['grandparentTitle']} — {$metadata['title']}"
                    : ($metadata['title'] ?? '(untitled)');

                $this->webhookEvent->update([
                    'processed_at' => now(),
                    'error' => "Could not match {$type} \"{$label}\" (ratingKey {$metadata['ratingKey']}) to a title.",
                ]);

                return;
            }

            $this->webhookEvent->markProcessed();
        } catch (Throwable $exception) {
            $this->webhookEvent->markFailed($exception->getMessage());
        }
    }
}
