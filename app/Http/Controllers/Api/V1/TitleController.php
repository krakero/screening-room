<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Tmdb\ImportTitle;
use App\Enums\CreditType;
use App\Enums\TitleType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ImportTitleRequest;
use App\Http\Resources\V1\TitleResource;
use App\Jobs\RefreshSeasonTrailer;
use App\Jobs\RefreshTitleFromTmdb;
use App\Jobs\RefreshTitleRatings;
use App\Jobs\RefreshTitleTrailer;
use App\Models\Credit;
use App\Models\Season;
use App\Models\Title;
use App\Services\ShowProgress;
use App\Services\Titles\TitleOverview;
use App\Services\Tmdb\TmdbClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

class TitleController extends Controller
{
    public function show(Title $title, ShowProgress $showProgress, TitleOverview $overview): TitleResource
    {
        $awaitingTrailer = $this->refreshTrailerIfStale($title);

        $progress = $title->isShow() ? $showProgress->for($title) : null;

        $currentSeason = $overview->currentSeason($title, $progress);
        $awaitingSeasonTrailer = $currentSeason !== null && $this->refreshSeasonTrailerIfStale($currentSeason);

        $this->refreshRatingsIfStale($title);

        $title->load([
            'seasons', 'externalRatings', 'follow', 'mediaLists:id,is_watchlist', 'rating', 'libraryStatus', 'networks',
            'watchProviders' => fn ($providers) => $providers->wherePivot('region', app(TmdbClient::class)->region()),
        ]);

        if ($title->isMovie()) {
            $title->load('plays');
        }

        return new TitleResource(
            $title,
            cast: $this->cast($title),
            seasonTrailer: $overview->seasonTrailer($title, $progress),
            seasonProgress: $title->isShow() ? $overview->seasonProgress($title->seasons) : [],
            plexPlayUrl: $overview->plexPlayUrl($title, $progress),
            progress: $progress,
            awaitingTrailer: $awaitingTrailer,
            awaitingSeasonTrailer: $awaitingSeasonTrailer,
            awaitingPlex: $overview->plexPending($title, $progress),
        );
    }

    public function import(ImportTitleRequest $request, ImportTitle $importTitle): JsonResponse
    {
        $type = TitleType::from($request->string('type')->toString());
        $tmdbId = $request->integer('tmdb_id');

        $existing = $this->findExisting($type, $tmdbId);

        if ($existing !== null) {
            return $this->importedResponse($existing);
        }

        $lock = Cache::lock("import-title:{$type->value}:{$tmdbId}", 30);

        if (! $lock->get()) {
            $existing = $this->findExisting($type, $tmdbId);

            if ($existing !== null) {
                return $this->importedResponse($existing);
            }

            return response()->json([
                'message' => __('This title is already being added. Please wait a moment and try again.'),
            ], 409);
        }

        try {
            $title = $importTitle->handle($type, $tmdbId);
        } finally {
            $lock->release();
        }

        return $this->importedResponse($title, 201);
    }

    public function refresh(Title $title): JsonResponse
    {
        RefreshTitleFromTmdb::dispatch($title->id);

        return response()->json(['message' => __('Refresh queued.')], 202);
    }

    private function findExisting(TitleType $type, int $tmdbId): ?Title
    {
        return Title::query()->where('type', $type)->where('tmdb_id', $tmdbId)->first();
    }

    private function importedResponse(Title $title, int $status = 200): JsonResponse
    {
        $title->loadMissing(['follow', 'mediaLists:id,is_watchlist', 'rating', 'libraryStatus']);

        return response()->json(new TitleResource(
            $title,
            cast: $this->cast($title),
            seasonTrailer: null,
            seasonProgress: [],
            plexPlayUrl: null,
            progress: null,
            awaitingTrailer: false,
            awaitingSeasonTrailer: false,
            awaitingPlex: false,
        ), $status);
    }

    /**
     * @return Collection<int, Credit>
     */
    private function cast(Title $title): Collection
    {
        return $title->credits()
            ->with('person')
            ->where('type', CreditType::Cast)
            ->orderBy('order')
            ->limit(12)
            ->get();
    }

    /**
     * Queue a trailer fetch when the title has no trailer and hasn't been checked in the last
     * 30 days. Returns whether a refresh was dispatched, for the response's `awaiting_trailer` flag.
     */
    private function refreshTrailerIfStale(Title $title): bool
    {
        if (filled($title->trailer_key)) {
            return false;
        }

        $checkedAt = $title->trailer_checked_at;

        if ($checkedAt === null || $checkedAt->lt(now()->subDays(30))) {
            RefreshTitleTrailer::dispatch($title);

            return true;
        }

        return false;
    }

    /**
     * Same as `refreshTrailerIfStale()`, but for a season.
     */
    private function refreshSeasonTrailerIfStale(Season $season): bool
    {
        if (filled($season->trailer_key)) {
            return false;
        }

        $checkedAt = $season->trailer_checked_at;

        if ($checkedAt === null || $checkedAt->lt(now()->subDays(30))) {
            RefreshSeasonTrailer::dispatch($season);

            return true;
        }

        return false;
    }

    private function refreshRatingsIfStale(Title $title): void
    {
        $checkedAt = $title->ratings_checked_at;

        if ($checkedAt === null || $checkedAt->lt(now()->subDays(7))) {
            RefreshTitleRatings::dispatch($title);
        }
    }
}
