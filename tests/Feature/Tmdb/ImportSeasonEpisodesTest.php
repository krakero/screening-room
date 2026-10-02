<?php

use App\Enums\TitleType;
use App\Jobs\ImportSeasonEpisodes;
use App\Models\Episode;
use App\Models\Play;
use App\Models\Season;
use App\Models\Title;
use App\Services\Tmdb\TmdbClient;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config(['services.tmdb.token' => 'test-token']);
    config(['services.tmdb.base_url' => 'https://api.themoviedb.org/3']);

    Http::preventStrayRequests();
});

function seasonOneFixture(): array
{
    return [
        'id' => 1396,
        'season/1' => json_decode(
            file_get_contents(base_path('tests/Fixtures/tmdb/show_1396_seasons.json')),
            true,
        )['season/1'],
    ];
}

test('it imports one season\'s episodes and marks it synced', function () {
    $title = Title::factory()->create(['type' => TitleType::Show, 'tmdb_id' => 1396]);
    $season = Season::factory()->notLoaded()->for($title)->create(['season_number' => 1]);

    Http::fake(['*/tv/1396*' => Http::response(seasonOneFixture())]);

    (new ImportSeasonEpisodes($title->id, 1))->handle(app(TmdbClient::class));

    $season->refresh();

    expect(Episode::where('season_id', $season->id)->count())->toBe(2)
        ->and($season->episode_count)->toBe(2)
        ->and($season->episodes_synced_at)->not->toBeNull();
});

test('it removes episodes no longer on tmdb unless they have plays', function () {
    $title = Title::factory()->create(['type' => TitleType::Show, 'tmdb_id' => 1396]);
    $season = Season::factory()->notLoaded()->for($title)->create(['season_number' => 1]);
    $stale = Episode::factory()->for($season)->create(['title_id' => $title->id, 'season_number' => 1, 'episode_number' => 97, 'tmdb_id' => 999999]);
    $keptDespiteRemoval = Episode::factory()->for($season)->create(['title_id' => $title->id, 'season_number' => 1, 'episode_number' => 98, 'tmdb_id' => 999998]);
    Play::factory()->for($keptDespiteRemoval, 'playable')->create();

    Http::fake(['*/tv/1396*' => Http::response(seasonOneFixture())]);

    (new ImportSeasonEpisodes($title->id, 1))->handle(app(TmdbClient::class));

    expect(Episode::find($stale->id))->toBeNull()
        ->and(Episode::find($keptDespiteRemoval->id))->not->toBeNull();
});
