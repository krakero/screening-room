<?php

namespace App\Actions\Plex;

use App\Enums\TitleType;
use App\Models\Episode;
use App\Models\PlexLibraryItem;
use App\Models\Title;
use Illuminate\Database\Eloquent\Builder;

/**
 * When a Plex webhook event carries a ratingKey + Guid[] (media.scrobble, library.new,
 * media.on-deck, …), cache the matching LOCAL Title/Episode's Plex availability directly from
 * the payload — no Plex or TMDB API call needed. Only matches items already in the library;
 * never imports (a webhook merely telling us something exists on Plex isn't a reason to start
 * tracking it).
 */
class UpsertPlexAvailabilityFromWebhook
{
    public function __construct(
        private readonly ResolvePlexAvailability $resolver,
    ) {}

    /**
     * @param  array<string, string>  $guids  The item's own external ids.
     * @param  array<string, string>  $showGuids  The show's external ids (legacy tvdb agent on an episode).
     */
    public function handle(
        string $type,
        string $ratingKey,
        ?string $machineIdentifier,
        array $guids,
        array $showGuids,
        ?int $seasonNumber,
        ?int $episodeNumber,
    ): void {
        if ($machineIdentifier === null) {
            return;
        }

        $this->indexItem($type, $ratingKey, $machineIdentifier, $guids);

        if ($type === 'movie') {
            $title = $this->findTitle(TitleType::Movie, $guids);

            if ($title !== null) {
                $this->resolver->applyKnown($title, $ratingKey, $machineIdentifier);
            }

            return;
        }

        if ($type !== 'episode' || $seasonNumber === null || $episodeNumber === null) {
            return;
        }

        $episode = $this->findEpisode($guids, $showGuids, $seasonNumber, $episodeNumber);

        if ($episode !== null) {
            $this->resolver->applyKnown($episode, $ratingKey, $machineIdentifier);
        }
    }

    /**
     * Upserts this ratingKey → external-id mapping into the same local library index that
     * `plex:index` builds, straight from the webhook payload — no Plex API call needed. Only
     * movie/show events carry the item's OWN guids here; an episode's `$guids` are its own tmdb/
     * tvdb ids, not the show's, so episodes are left to the index's Title-level entries and
     * forEpisode()'s live per-page lookup instead of being indexed themselves.
     *
     * @param  array<string, string>  $guids
     */
    private function indexItem(string $type, string $ratingKey, string $machineIdentifier, array $guids): void
    {
        if ($guids === [] || ! in_array($type, ['movie', 'show'], true)) {
            return;
        }

        PlexLibraryItem::query()->updateOrCreate(
            ['plex_rating_key' => $ratingKey, 'machine_identifier' => $machineIdentifier],
            [
                'type' => $type,
                'tmdb_id' => $guids['tmdb'] ?? null,
                'imdb_id' => $guids['imdb'] ?? null,
                'tvdb_id' => $guids['tvdb'] ?? null,
                'indexed_at' => now(),
            ],
        );
    }

    /**
     * @param  array<string, string>  $guids
     * @param  array<string, string>  $showGuids
     */
    private function findEpisode(array $guids, array $showGuids, int $seasonNumber, int $episodeNumber): ?Episode
    {
        if (isset($guids['tmdb'])) {
            $episode = Episode::query()->where('tmdb_id', $guids['tmdb'])->first();

            if ($episode !== null) {
                return $episode;
            }
        }

        if (isset($guids['tvdb'])) {
            $episode = Episode::query()->where('tvdb_id', $guids['tvdb'])->first();

            if ($episode !== null) {
                return $episode;
            }
        }

        $show = $this->findTitle(TitleType::Show, $showGuids !== [] ? $showGuids : $guids);

        if ($show === null) {
            return null;
        }

        return Episode::query()
            ->where('title_id', $show->id)
            ->where('season_number', $seasonNumber)
            ->where('episode_number', $episodeNumber)
            ->first();
    }

    /**
     * @param  array<string, string>  $guids
     */
    private function findTitle(TitleType $type, array $guids): ?Title
    {
        if ($guids === []) {
            return null;
        }

        return Title::query()
            ->where('type', $type)
            ->where(function (Builder $query) use ($guids): void {
                if (isset($guids['tmdb'])) {
                    $query->orWhere('tmdb_id', $guids['tmdb']);
                }

                if (isset($guids['tvdb'])) {
                    $query->orWhere('tvdb_id', $guids['tvdb']);
                }

                if (isset($guids['imdb'])) {
                    $query->orWhere('imdb_id', $guids['imdb']);
                }
            })
            ->first();
    }
}
