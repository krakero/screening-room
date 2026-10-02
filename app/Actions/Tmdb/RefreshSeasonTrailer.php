<?php

namespace App\Actions\Tmdb;

use App\Models\Season;
use App\Services\Tmdb\TmdbClient;
use App\Services\Tmdb\TmdbException;
use Illuminate\Support\Carbon;

class RefreshSeasonTrailer
{
    public function __construct(
        private readonly TmdbClient $tmdb,
        private readonly PickTrailer $pickTrailer,
    ) {}

    /**
     * Fetch a season's trailer without a full TMDB re-import. Swallows TMDB failures so a
     * stale or unreachable provider never blocks the caller — on failure `trailer_checked_at`
     * is not advanced, so the season is retried on its next stale check. Skips entirely
     * (no request, no `trailer_checked_at` write) when TMDB isn't configured.
     */
    public function handle(Season $season): void
    {
        if (blank($this->tmdb->token())) {
            return;
        }

        try {
            $data = $this->tmdb->seasonVideos($season->title->tmdb_id, $season->season_number);
        } catch (TmdbException) {
            return;
        }

        $trailer = $this->pickTrailer->handle($data['results'] ?? []);

        $season->forceFill([
            'trailer_site' => $trailer['site'] ?? null,
            'trailer_key' => $trailer['key'] ?? null,
            'trailer_checked_at' => Carbon::now(),
        ])->save();
    }
}
