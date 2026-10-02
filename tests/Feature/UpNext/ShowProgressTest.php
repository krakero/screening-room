<?php

use App\Enums\PlaySource;
use App\Models\Episode;
use App\Models\Season;
use App\Models\Title;
use App\Services\ShowProgress;
use Illuminate\Support\Facades\DB;

function makeShowWithEpisodes(bool $inProduction, bool $watchAllAired): array
{
    $title = Title::factory()->show()->create(['in_production' => $inProduction]);
    $season = Season::factory()->for($title)->create(['season_number' => 1]);

    $special = Episode::factory()->for($season)->aired()->special()->create(['title_id' => $title->id]);
    $airedWatched = Episode::factory()->for($season)->aired()->create(['title_id' => $title->id, 'episode_number' => 1]);
    $airedUnwatched = Episode::factory()->for($season)->aired()->create(['title_id' => $title->id, 'episode_number' => 2]);
    $unaired = Episode::factory()->for($season)->unaired()->create(['title_id' => $title->id, 'episode_number' => 3]);

    $airedWatched->plays()->create(['watched_at' => now(), 'source' => PlaySource::Manual]);
    $special->plays()->create(['watched_at' => now(), 'source' => PlaySource::Manual]);

    if ($watchAllAired) {
        $airedUnwatched->plays()->create(['watched_at' => now(), 'source' => PlaySource::Manual]);
    }

    return compact('title', 'season', 'special', 'airedWatched', 'airedUnwatched', 'unaired');
}

test('counts aired regular episodes excluding season 0 and unaired episodes', function () {
    ['title' => $title] = makeShowWithEpisodes(inProduction: true, watchAllAired: false);

    $progress = app(ShowProgress::class);

    expect($progress->airedEpisodeCount($title))->toBe(2)
        ->and($progress->watchedEpisodeCount($title))->toBe(1)
        ->and($progress->percent($title))->toBe(50.0);
});

test('next episode is the lowest unwatched aired episode', function () {
    ['title' => $title, 'airedUnwatched' => $airedUnwatched] = makeShowWithEpisodes(inProduction: true, watchAllAired: false);

    $progress = app(ShowProgress::class);

    expect($progress->nextEpisode($title)?->is($airedUnwatched))->toBeTrue();
});

test('a returning series with everything watched is not complete', function () {
    ['title' => $title] = makeShowWithEpisodes(inProduction: true, watchAllAired: true);

    $progress = app(ShowProgress::class);

    expect($progress->isComplete($title))->toBeFalse()
        ->and($progress->nextEpisode($title))->toBeNull();
});

test('an ended series with everything watched is complete', function () {
    ['title' => $title] = makeShowWithEpisodes(inProduction: false, watchAllAired: true);

    $progress = app(ShowProgress::class);

    expect($progress->isComplete($title))->toBeTrue();
});

test('an unloaded season falls back to its tmdb episode_count for the total and blocks completion', function () {
    $title = Title::factory()->show()->create(['in_production' => false]);

    $loadedSeason = Season::factory()->for($title)->create(['season_number' => 1]);
    $watched = Episode::factory()->for($loadedSeason)->aired()->create(['title_id' => $title->id, 'episode_number' => 1]);
    $watched->plays()->create(['watched_at' => now(), 'source' => PlaySource::Manual]);

    Season::factory()->notLoaded()->for($title)->create(['season_number' => 2, 'episode_count' => 6]);

    $progress = app(ShowProgress::class);

    expect($progress->airedEpisodeCount($title))->toBe(7)
        ->and($progress->watchedEpisodeCount($title))->toBe(1)
        ->and($progress->isComplete($title))->toBeFalse();
});

test('a show is not complete while any regular season is unloaded, even if every known episode is watched', function () {
    $title = Title::factory()->show()->create(['in_production' => false]);

    $loadedSeason = Season::factory()->for($title)->create(['season_number' => 1]);
    $watched = Episode::factory()->for($loadedSeason)->aired()->create(['title_id' => $title->id, 'episode_number' => 1]);
    $watched->plays()->create(['watched_at' => now(), 'source' => PlaySource::Manual]);

    Season::factory()->notLoaded()->for($title)->create(['season_number' => 2, 'episode_count' => 0]);

    $progress = app(ShowProgress::class);

    expect($progress->isComplete($title))->toBeFalse();
});

test('a show with no aired episodes is not complete', function () {
    $title = Title::factory()->show()->create(['in_production' => false]);

    $progress = app(ShowProgress::class);

    expect($progress->isComplete($title))->toBeFalse()
        ->and($progress->airedEpisodeCount($title))->toBe(0)
        ->and($progress->percent($title))->toBe(0.0);
});

test('forMany batches progress for multiple shows without N+1 queries', function () {
    ['title' => $titleA, 'airedUnwatched' => $nextA] = makeShowWithEpisodes(inProduction: true, watchAllAired: false);
    ['title' => $titleB] = makeShowWithEpisodes(inProduction: false, watchAllAired: true);

    $progress = app(ShowProgress::class);

    DB::enableQueryLog();
    $results = $progress->forMany(collect([$titleA, $titleB]));
    $queries = count(DB::getQueryLog());
    DB::disableQueryLog();

    // 5 queries total regardless of title count: aired episode ids, plays, seasons (for the
    // unloaded-season episode_count fallback), rewatch start dates, and the next episodes —
    // still O(1), not O(n).
    expect($queries)->toBeLessThanOrEqual(5)
        ->and($results->get($titleA->id)->nextEpisode?->is($nextA))->toBeTrue()
        ->and($results->get($titleA->id)->isComplete)->toBeFalse()
        ->and($results->get($titleB->id)->isComplete)->toBeTrue();
});
