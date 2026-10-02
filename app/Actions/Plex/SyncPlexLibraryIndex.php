<?php

namespace App\Actions\Plex;

use App\Models\PlexLibraryEpisode;
use App\Models\PlexLibraryItem;
use App\Models\Title;
use App\Services\Plex\PlexClient;
use App\Support\IntegrationSettings;
use Closure;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Rebuilds the local Plex library index (`plex_library_items`) by paging every movie/show section
 * with `includeGuids=1` — Plex's own `guid` filter only matches an item's PRIMARY guid, never an
 * external id, so a live per-title lookup can never work; this is the only reliable way to map
 * tmdb/imdb/tvdb ids to ratingKeys. Also refreshes every Title's cached `plex_items` row from the
 * freshly synced index, since matching against it is now just a cheap indexed DB query rather than
 * a live Plex call.
 */
class SyncPlexLibraryIndex
{
    private const PAGE_SIZE = 200;

    /**
     * If a run indexes fewer than this fraction of the previous successful run's item count, it's
     * treated as a bad/partial run (server hiccup, half-empty library scan, …) and the stale-item
     * cleanup below is skipped rather than trusted to mean everything else was actually removed.
     */
    private const MIN_ITEM_RATIO = 0.5;

    public function __construct(
        private readonly PlexClient $client,
        private readonly ResolvePlexAvailability $resolver,
        private readonly IntegrationSettings $settings,
    ) {}

    /**
     * @param  ?Closure(array{section: string, title: string}): void  $onProgress  Invoked once per
     *                                                                             item encountered (matched or skipped), for a command-line progress bar.
     * @return array{sections: int, items_indexed: int, episodes_indexed: int, removed: int, titles_refreshed: int, cleanup_skipped: bool, cleanup_skip_reason: ?string}
     */
    public function handle(bool $force = false, ?Closure $onProgress = null): array
    {
        $syncedAt = Carbon::now();
        $machineIdentifier = $this->client->machineIdentifier(shortTimeout: false);
        $sections = $this->client->librarySections();
        $itemsIndexed = 0;
        $episodesIndexed = 0;

        foreach ($sections as $section) {
            [$indexed, $episodes] = $this->syncSection($section, $machineIdentifier, $syncedAt, $onProgress);
            $itemsIndexed += $indexed;
            $episodesIndexed += $episodes;
        }

        $skipReason = $force ? null : $this->skipCleanupReason($sections, $itemsIndexed, $machineIdentifier);
        $removed = 0;

        if ($skipReason === null) {
            $removed = PlexLibraryItem::query()
                ->where('machine_identifier', $machineIdentifier)
                ->where('indexed_at', '<', $syncedAt)
                ->delete();

            PlexLibraryEpisode::query()
                ->where('machine_identifier', $machineIdentifier)
                ->where('indexed_at', '<', $syncedAt)
                ->delete();
        } else {
            Log::warning("plex:index skipped removing stale library rows: {$skipReason}", [
                'sections' => count($sections),
                'items_indexed' => $itemsIndexed,
                'machine_identifier' => $machineIdentifier,
            ]);
        }

        $titlesRefreshed = 0;

        Title::query()->chunkById(200, function ($titles) use (&$titlesRefreshed): void {
            foreach ($titles as $title) {
                $this->resolver->forTitle($title, force: true);
                $titlesRefreshed++;
            }
        });

        return [
            'sections' => count($sections),
            'items_indexed' => $itemsIndexed,
            'episodes_indexed' => $episodesIndexed,
            'removed' => $removed,
            'titles_refreshed' => $titlesRefreshed,
            'cleanup_skipped' => $skipReason !== null,
            'cleanup_skip_reason' => $skipReason,
        ];
    }

    /**
     * @param  array<int, array{key: string, type: string, title: string}>  $sections
     */
    private function skipCleanupReason(array $sections, int $itemsIndexed, ?string $machineIdentifier): ?string
    {
        if ($sections === []) {
            return 'no library sections were found';
        }

        if ($itemsIndexed === 0) {
            return 'no items were indexed';
        }

        if ($machineIdentifier === null) {
            return 'the Plex machine identifier was unreachable';
        }

        $previous = $this->settings->get('plex.library_index');
        $previousCount = is_array($previous) && ($previous['status'] ?? null) === 'success'
            ? (int) ($previous['items_indexed'] ?? 0)
            : null;

        if ($previousCount !== null && $previousCount > 0 && $itemsIndexed < $previousCount * self::MIN_ITEM_RATIO) {
            return "items indexed ({$itemsIndexed}) dropped more than 50% from the previous run ({$previousCount})";
        }

        return null;
    }

    /**
     * @param  array{key: string, type: string, title: string}  $section
     * @param  ?Closure(array{section: string, title: string}): void  $onProgress
     * @return array{0: int, 1: int} [items indexed, episodes indexed]
     */
    private function syncSection(array $section, ?string $machineIdentifier, Carbon $syncedAt, ?Closure $onProgress = null): array
    {
        $indexed = 0;
        $episodesIndexed = 0;
        $start = 0;

        while (true) {
            $page = $this->client->sectionItems($section['key'], $start, self::PAGE_SIZE);
            $items = $page['items'];

            if ($items === []) {
                break;
            }

            foreach ($items as $item) {
                $ratingKey = $this->indexItem($item, $section['type'], $section['key'], $machineIdentifier, $syncedAt);

                $onProgress?->__invoke([
                    'section' => $section['title'],
                    'title' => (string) ($item['title'] ?? 'Untitled'),
                ]);

                if ($ratingKey === null) {
                    continue;
                }

                $indexed++;

                if ($section['type'] === 'show') {
                    $episodesIndexed += $this->syncShowEpisodes($ratingKey, $machineIdentifier, $syncedAt);
                }
            }

            $start += self::PAGE_SIZE;

            if (count($items) < self::PAGE_SIZE) {
                break;
            }
        }

        return [$indexed, $episodesIndexed];
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function indexItem(array $item, string $type, string $sectionKey, ?string $machineIdentifier, Carbon $syncedAt): ?string
    {
        $ratingKey = isset($item['ratingKey']) ? (string) $item['ratingKey'] : null;
        $guids = PlexClient::parseGuids($item['Guid'] ?? []);

        // Items with a local:// (or otherwise unrecognized) guid and no Guid[] array can't match a
        // Title by external id, so there's nothing useful to index.
        if ($ratingKey === null || $guids === []) {
            return null;
        }

        PlexLibraryItem::query()->updateOrCreate(
            ['plex_rating_key' => $ratingKey, 'machine_identifier' => $machineIdentifier],
            [
                'type' => $type,
                'tmdb_id' => $guids['tmdb'] ?? null,
                'imdb_id' => $guids['imdb'] ?? null,
                'tvdb_id' => $guids['tvdb'] ?? null,
                'section_key' => $sectionKey,
                'indexed_at' => $syncedAt,
            ],
        );

        return $ratingKey;
    }

    /**
     * Fetches a show's full episode list once (`allLeaves`) and stores it in the local
     * `plex_library_episodes` index, so `plex:refresh-availability` never needs a live lookup per
     * stale episode — only per show, and only when the index doesn't already have the episode.
     */
    private function syncShowEpisodes(string $showRatingKey, ?string $machineIdentifier, Carbon $syncedAt): int
    {
        try {
            $episodes = $this->client->showEpisodes($showRatingKey, shortTimeout: false);
        } catch (Throwable) {
            return 0;
        }

        foreach ($episodes as $episode) {
            PlexLibraryEpisode::query()->updateOrCreate(
                [
                    'show_rating_key' => $showRatingKey,
                    'machine_identifier' => $machineIdentifier,
                    'season_number' => $episode['season_number'],
                    'episode_number' => $episode['episode_number'],
                ],
                [
                    'plex_rating_key' => $episode['rating_key'],
                    'indexed_at' => $syncedAt,
                ],
            );
        }

        return count($episodes);
    }
}
