<?php

use App\Actions\Plex\ImportRecentHistory;
use App\Enums\PlaySource;
use App\Enums\TitleType;
use App\Jobs\PollPlexHistory;
use App\Models\Episode;
use App\Models\Play;
use App\Models\Season;
use App\Models\Title;
use App\Support\IntegrationSettings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::preventStrayRequests();
});

function pollFixture(string $name): array
{
    return json_decode(file_get_contents(base_path("tests/Fixtures/plex/{$name}.json")), true);
}

/**
 * The `history_all` fixture's viewedAt timestamps are static, so the job's
 * "last 20 minutes" window (built from Carbon::now()) would exclude them by
 * the time the suite runs. Rewrite them to be recent for the poll job tests.
 */
function recentHistoryFixture(): array
{
    $history = pollFixture('history_all');

    foreach ($history['MediaContainer']['Metadata'] as $index => $item) {
        $history['MediaContainer']['Metadata'][$index]['viewedAt'] = Carbon::now()->subMinutes(5 + $index)->timestamp;
    }

    return $history;
}

test('it does nothing when plex settings are not configured', function () {
    app(PollPlexHistory::class)->handle(app(ImportRecentHistory::class));

    expect(Play::count())->toBe(0);
});

test('it runs and omits the account filter when no account id is configured', function () {
    app(IntegrationSettings::class)->setMany([
        'plex.url' => 'http://plex.local:32400',
        'plex.token' => 'plex-token',
    ]);

    $movie = Title::factory()->create(['type' => TitleType::Movie, 'tmdb_id' => 603]);
    $show = Title::factory()->create(['type' => TitleType::Show, 'tmdb_id' => 1399]);
    $season = Season::factory()->for($show, 'title')->create(['season_number' => 1]);
    Episode::factory()->for($show, 'title')->for($season, 'season')->create([
        'season_number' => 1,
        'episode_number' => 1,
    ]);

    Http::fake([
        '*plex.local:32400/status/sessions/history/all*' => Http::response(recentHistoryFixture()),
        '*plex.local:32400/library/metadata/501*' => Http::response(pollFixture('metadata_movie')),
        '*plex.local:32400/library/metadata/800*' => Http::response(pollFixture('metadata_show')),
    ]);

    app(PollPlexHistory::class)->handle(app(ImportRecentHistory::class));

    Http::assertSent(fn ($request): bool => str_contains($request->url(), 'status/sessions/history/all')
        && ! str_contains($request->url(), 'accountID'));

    expect(Play::where('playable_type', 'title')->where('playable_id', $movie->id)->exists())->toBeTrue();
});

test('it records plays from recent history for movies and episodes', function () {
    app(IntegrationSettings::class)->setMany([
        'plex.url' => 'http://plex.local:32400',
        'plex.token' => 'plex-token',
        'plex.account_id' => '1',
    ]);

    $movie = Title::factory()->create(['type' => TitleType::Movie, 'tmdb_id' => 603]);
    $show = Title::factory()->create(['type' => TitleType::Show, 'tmdb_id' => 1399]);
    $season = Season::factory()->for($show, 'title')->create(['season_number' => 1]);
    $episode = Episode::factory()->for($show, 'title')->for($season, 'season')->create([
        'season_number' => 1,
        'episode_number' => 1,
    ]);

    Http::fake([
        '*plex.local:32400/status/sessions/history/all*' => Http::response(recentHistoryFixture()),
        '*plex.local:32400/library/metadata/501*' => Http::response(pollFixture('metadata_movie')),
        '*plex.local:32400/library/metadata/800*' => Http::response(pollFixture('metadata_show')),
    ]);

    app(PollPlexHistory::class)->handle(app(ImportRecentHistory::class));

    expect(Play::where('playable_type', 'title')->where('playable_id', $movie->id)->exists())->toBeTrue()
        ->and(Play::where('playable_type', 'episode')->where('playable_id', $episode->id)->exists())->toBeTrue()
        ->and(Play::count())->toBe(2);
});

test('a webhook play is not duplicated by a subsequent poll', function () {
    app(IntegrationSettings::class)->setMany([
        'plex.url' => 'http://plex.local:32400',
        'plex.token' => 'plex-token',
        'plex.account_id' => '1',
    ]);

    $movie = Title::factory()->create(['type' => TitleType::Movie, 'tmdb_id' => 603]);
    $show = Title::factory()->create(['type' => TitleType::Show, 'tmdb_id' => 1399]);
    $season = Season::factory()->for($show, 'title')->create(['season_number' => 1]);
    Episode::factory()->for($show, 'title')->for($season, 'season')->create([
        'season_number' => 1,
        'episode_number' => 1,
    ]);

    $history = recentHistoryFixture();
    $movieViewedAt = $history['MediaContainer']['Metadata'][0]['viewedAt'];

    $movie->plays()->create([
        'watched_at' => now(),
        'source' => PlaySource::Plex,
        'external_id' => "501:{$movieViewedAt}",
    ]);

    Http::fake([
        '*plex.local:32400/status/sessions/history/all*' => Http::response($history),
        '*plex.local:32400/library/metadata/501*' => Http::response(pollFixture('metadata_movie')),
        '*plex.local:32400/library/metadata/800*' => Http::response(pollFixture('metadata_show')),
    ]);

    app(PollPlexHistory::class)->handle(app(ImportRecentHistory::class));

    expect($movie->plays()->count())->toBe(1);
});
