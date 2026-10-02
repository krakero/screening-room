<?php

use App\Actions\Tmdb\ImportShow;
use App\Enums\CreditType;
use App\Enums\TitleType;
use App\Jobs\ImportSeasonEpisodes;
use App\Models\Episode;
use App\Models\Play;
use App\Models\Season;
use App\Models\Title;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config(['services.tmdb.token' => 'test-token']);
    config(['services.tmdb.base_url' => 'https://api.themoviedb.org/3']);

    Http::preventStrayRequests();
});

function showFixture(): array
{
    return json_decode(
        file_get_contents(base_path('tests/Fixtures/tmdb/show_1396.json')),
        true,
    );
}

test('it imports a show with season summaries, no episodes, and queues one season-episode job per season', function () {
    Bus::fake();

    Http::fake([
        '*/tv/1396*' => Http::response(showFixture()),
    ]);

    $title = app(ImportShow::class)->handle(1396);

    expect($title->type)->toBe(TitleType::Show)
        ->and($title->tmdb_id)->toBe(1396)
        ->and($title->name)->toBe('Breaking Bad')
        ->and($title->imdb_id)->toBe('tt0903747')
        ->and($title->tvdb_id)->toBe(81189)
        ->and($title->in_production)->toBeFalse()
        ->and($title->release_date->toDateString())->toBe('2008-01-20')
        ->and($title->last_air_date->toDateString())->toBe('2013-09-29')
        ->and($title->genres)->toBe(['Drama', 'Crime']);

    expect(Season::where('title_id', $title->id)->count())->toBe(3)
        ->and(Episode::where('title_id', $title->id)->count())->toBe(0);

    $seasonOne = Season::where('title_id', $title->id)->where('season_number', 1)->first();
    expect($seasonOne->name)->toBe('Season 1')
        ->and($seasonOne->episode_count)->toBe(7)
        ->and($seasonOne->episodes_synced_at)->toBeNull();

    $cast = $title->credits()->where('type', CreditType::Cast)->get();
    expect($cast)->toHaveCount(2)
        ->and($cast->first()->character)->toBe('Walter White');

    $crew = $title->credits()->where('type', CreditType::Crew)->get();
    expect($crew->pluck('job')->all())->toBe(['Creator', 'Executive Producer']);

    Bus::assertBatched(function ($batch) use ($title) {
        $seasonNumbers = collect($batch->jobs)
            ->map(fn (ImportSeasonEpisodes $job) => $job->seasonNumber)
            ->sort()
            ->values()
            ->all();

        return $batch->jobs->every(fn (ImportSeasonEpisodes $job) => $job->titleId === $title->id)
            && $seasonNumbers === [0, 1, 2];
    });
});

test('it stores the best trailer when videos are present', function () {
    Bus::fake();

    $fixture = json_decode(
        file_get_contents(base_path('tests/Fixtures/tmdb/show_1396_with_trailer.json')),
        true,
    );

    Http::fake([
        '*/tv/1396*' => Http::response($fixture),
    ]);

    $title = app(ImportShow::class)->handle(1396);

    expect($title->trailer_site)->toBe('YouTube')
        ->and($title->trailer_key)->toBe('HhesaQXLuRY');
});

test('it stores no trailer when there are no videos', function () {
    Bus::fake();

    Http::fake([
        '*/tv/1396*' => Http::response(showFixture()),
    ]);

    $title = app(ImportShow::class)->handle(1396);

    expect($title->trailer_site)->toBeNull()
        ->and($title->trailer_key)->toBeNull();
});

test('re-importing a show is idempotent and queues jobs again', function () {
    Bus::fake();

    Http::fake([
        '*/tv/1396*' => Http::response(showFixture()),
    ]);

    $first = app(ImportShow::class)->handle(1396);
    $second = app(ImportShow::class)->handle(1396);

    expect($second->id)->toBe($first->id)
        ->and(Title::count())->toBe(1)
        ->and(Season::count())->toBe(3);

    Bus::assertBatchCount(2);
});

test('it normalizes blank air dates to null', function () {
    Bus::fake();

    $show = json_decode(
        file_get_contents(base_path('tests/Fixtures/tmdb/show_1396_blank_dates.json')),
        true,
    );

    Http::fake([
        '*/tv/1396*' => Http::response($show),
    ]);

    $title = app(ImportShow::class)->handle(1396);

    expect($title->release_date)->toBeNull()
        ->and($title->last_air_date)->toBeNull();

    $season = Season::where('title_id', $title->id)->where('season_number', 1)->firstOrFail();
    expect($season->air_date)->toBeNull();
});

function showFixtureWithoutSeasonTwo(): array
{
    $show = showFixture();
    $show['seasons'] = collect($show['seasons'])
        ->reject(fn (array $season): bool => $season['season_number'] === 2)
        ->values()
        ->all();

    return $show;
}

test('it removes a season that disappeared from tmdb when it has no plays', function () {
    Bus::fake();

    Http::fake([
        '*/tv/1396*' => Http::sequence()
            ->push(showFixture())
            ->push(showFixtureWithoutSeasonTwo()),
    ]);

    $title = app(ImportShow::class)->handle(1396);
    expect(Season::where('title_id', $title->id)->count())->toBe(3);

    app(ImportShow::class)->handle(1396);

    expect(Season::where('title_id', $title->id)->count())->toBe(2)
        ->and(Season::where('title_id', $title->id)->where('season_number', 2)->exists())->toBeFalse();
});

test('it keeps a season that disappeared from tmdb when it has plays', function () {
    Bus::fake();

    Http::fake([
        '*/tv/1396*' => Http::sequence()
            ->push(showFixture())
            ->push(showFixtureWithoutSeasonTwo()),
    ]);

    $title = app(ImportShow::class)->handle(1396);

    $season2 = Season::where('title_id', $title->id)->where('season_number', 2)->firstOrFail();
    $episode = Episode::factory()->for($season2)->create(['title_id' => $title->id, 'season_number' => 2]);
    Play::factory()->for($episode, 'playable')->create();

    app(ImportShow::class)->handle(1396);

    expect(Season::where('title_id', $title->id)->where('season_number', 2)->exists())->toBeTrue()
        ->and(Episode::where('title_id', $title->id)->where('season_number', 2)->exists())->toBeTrue();
});
