<?php

namespace App\Actions\Plex;

use App\Services\Plex\PlexClient;
use App\Support\IntegrationSettings;
use Illuminate\Support\Carbon;

class ImportRecentHistory
{
    public function __construct(
        private readonly PlexClient $plex,
        private readonly RecordScrobble $recordScrobble,
        private readonly IntegrationSettings $settings,
    ) {}

    /**
     * Import watch history since $since, recording a Play for each newly matched item (unless
     * $dryRun). Requires `plex.url` and `plex.token` — returns a zeroed summary without them.
     *
     * @return array{fetched: int, recorded: int, duplicates: int, ignored_account: int, unmatched: int, unmatched_items: array<int, string>}
     */
    public function handle(Carbon $since, bool $dryRun = false): array
    {
        $summary = [
            'fetched' => 0,
            'recorded' => 0,
            'duplicates' => 0,
            'ignored_account' => 0,
            'unmatched' => 0,
            'unmatched_items' => [],
        ];

        if (! $this->settings->configured('plex.url', 'plex.token')) {
            return $summary;
        }

        foreach ($this->plex->recentHistory($since) as $entry) {
            $summary['fetched']++;

            $guids = $entry['type'] === 'movie' ? $this->plex->metadataGuids($entry['rating_key']) : [];

            // A token-backed lookup on the show's own ratingKey gives its ids directly, so it's
            // passed as `show_guids` (see RecordScrobble) rather than the episode's own `guids`.
            $showGuids = $entry['type'] === 'episode' && $entry['grandparent_rating_key'] !== null
                ? $this->plex->metadataGuids($entry['grandparent_rating_key'])
                : [];

            $result = $this->recordScrobble->attempt([
                'account_id' => $entry['account_id'],
                'rating_key' => $entry['rating_key'],
                'viewed_at' => $entry['viewed_at'],
                'type' => $entry['type'],
                'title' => $entry['title'],
                'grandparent_title' => $entry['grandparent_title'],
                'season_number' => $entry['season_number'],
                'episode_number' => $entry['episode_number'],
                'guids' => $guids,
                'show_guids' => $showGuids,
            ], persist: ! $dryRun);

            match ($result['outcome']) {
                'recorded' => $summary['recorded']++,
                'duplicate' => $summary['duplicates']++,
                'ignored_account' => $summary['ignored_account']++,
                'unmatched' => $summary['unmatched']++,
            };

            if ($result['outcome'] === 'unmatched') {
                $label = $entry['grandparent_title'] !== null
                    ? "{$entry['grandparent_title']} — {$entry['title']}"
                    : $entry['title'];

                $summary['unmatched_items'][] = "{$label} ({$entry['type']}, ratingKey {$entry['rating_key']})";
            }
        }

        return $summary;
    }
}
