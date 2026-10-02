<?php

namespace App\Actions\Tmdb;

use App\Models\Title;
use App\Services\Tmdb\TmdbClient;
use App\Services\Tmdb\TmdbException;
use Illuminate\Support\Carbon;

class RefreshTrailer
{
    public function __construct(
        private readonly TmdbClient $tmdb,
        private readonly PickTrailer $pickTrailer,
    ) {}

    /**
     * Fetch a title's trailer without a full TMDB re-import. Swallows TMDB failures so a
     * stale or unreachable provider never blocks the caller — on failure `trailer_checked_at`
     * is not advanced, so the title is retried on its next stale check. Skips entirely
     * (no request, no `trailer_checked_at` write) when TMDB isn't configured.
     */
    public function handle(Title $title): void
    {
        if (blank($this->tmdb->token())) {
            return;
        }

        try {
            $data = $title->isMovie()
                ? $this->tmdb->movieVideos($title->tmdb_id)
                : $this->tmdb->showVideos($title->tmdb_id);
        } catch (TmdbException) {
            return;
        }

        $trailer = $this->pickTrailer->handle($data['results'] ?? []);

        $title->forceFill([
            'trailer_site' => $trailer['site'] ?? null,
            'trailer_key' => $trailer['key'] ?? null,
            'trailer_checked_at' => Carbon::now(),
        ])->save();
    }
}
