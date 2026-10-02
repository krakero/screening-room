<?php

namespace App\Services\Seasons;

use App\Actions\Plex\ResolvePlexAvailability;
use App\Models\Episode;
use App\Models\Title;
use App\Support\IntegrationSettings;
use App\Support\WatchedSince;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Shared season-detail computations used by both the web season page and the API's
 * season-detail endpoint, so the two never drift apart. Every method takes the season's
 * episodes already loaded with their `plays` relation.
 */
class SeasonOverview
{
    public function __construct(
        private readonly ResolvePlexAvailability $plex,
        private readonly IntegrationSettings $settings,
    ) {}

    /**
     * @param  Collection<int, Episode>  $episodes
     * @param  CarbonInterface|null  $since  while the show is rewatching, only plays since this date count
     *                                       as watched (see WatchedSince); null counts any play, ever.
     */
    public function nextEpisode(Collection $episodes, ?CarbonInterface $since = null): ?Episode
    {
        return $episodes
            ->filter(fn (Episode $episode) => $episode->hasAired())
            ->first(fn (Episode $episode) => ! WatchedSince::watched($episode->plays, $since));
    }

    /**
     * Aired episodes that haven't been marked watched — the count shown in the
     * "mark season watched" confirmation.
     *
     * @param  Collection<int, Episode>  $episodes
     * @param  CarbonInterface|null  $since  see nextEpisode()
     */
    public function airedUnwatchedCount(Collection $episodes, ?CarbonInterface $since = null): int
    {
        return $episodes
            ->filter(fn (Episode $episode) => $episode->hasAired() && ! WatchedSince::watched($episode->plays, $since))
            ->count();
    }

    /**
     * Plex "play" links for this season's aired episodes, keyed by episode id. Unaired episodes
     * are skipped (they can't be on the server yet) and missing/unconfigured items are omitted.
     * Reads cached `plex_items` rows in one batched query (no live Plex call, no N+1); episodes
     * with no row yet each queue a background resolve job — see `pendingCount()`.
     *
     * @param  Collection<int, Episode>  $episodes
     * @return array<int, string>
     */
    public function plexPlayUrls(Title $title, Collection $episodes): array
    {
        $aired = $episodes->filter(fn (Episode $episode): bool => $episode->hasAired());

        $cached = $this->plex->forEpisodes($aired);

        return $aired
            ->mapWithKeys(fn (Episode $episode) => [$episode->id => $cached->get($episode->id)?->playUrl()])
            ->filter()
            ->all();
    }

    /**
     * True while at least one aired episode's Plex availability hasn't been resolved yet (no
     * `plex_items` row), i.e. a background job is queued and the season's Plex links are still
     * incomplete — the caller should poll and re-render once it clears.
     *
     * @param  Collection<int, Episode>  $episodes
     */
    public function hasPendingPlex(Title $title, Collection $episodes): bool
    {
        $aired = $episodes->filter(fn (Episode $episode): bool => $episode->hasAired());

        return $this->plex->pendingEpisodeCount($aired) > 0;
    }
}
