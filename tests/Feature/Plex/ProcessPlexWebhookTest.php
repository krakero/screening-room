<?php

use App\Actions\Plex\RecordScrobble;
use App\Actions\Plex\UpsertPlexAvailabilityFromWebhook;
use App\Enums\TitleType;
use App\Jobs\ProcessPlexWebhook;
use App\Models\Episode;
use App\Models\Play;
use App\Models\PlexItem;
use App\Models\Season;
use App\Models\Title;
use App\Models\WebhookEvent;
use App\Services\Plex\PlexClient;
use App\Support\IntegrationSettings;
use Illuminate\Support\Facades\Http;

function plexWebhookFixture(string $name): array
{
    return json_decode(file_get_contents(base_path("tests/Fixtures/plex/{$name}.json")), true);
}

function processWebhook(array $payload): WebhookEvent
{
    $webhookEvent = WebhookEvent::create([
        'source' => 'plex',
        'event' => $payload['event'] ?? null,
        'payload' => $payload,
    ]);

    (new ProcessPlexWebhook($webhookEvent))->handle(app(PlexClient::class), app(RecordScrobble::class), app(UpsertPlexAvailabilityFromWebhook::class), app(IntegrationSettings::class));

    return $webhookEvent->refresh();
}

describe('webhook-only setup (no Plex URL, token, or account id configured)', function () {
    beforeEach(function () {
        Http::preventStrayRequests();
    });

    test('it records a play for a movie scrobble from the payload guids alone', function () {
        $title = Title::factory()->create(['type' => TitleType::Movie, 'tmdb_id' => 603]);

        $webhookEvent = processWebhook(plexWebhookFixture('webhook_movie_scrobble'));

        expect(Play::where('playable_type', 'title')->where('playable_id', $title->id)->exists())->toBeTrue()
            ->and($webhookEvent->processed_at)->not->toBeNull();
    });

    test('it records a play for an episode scrobble from the episode’s own guids in the payload, with no API call', function () {
        $show = Title::factory()->create(['type' => TitleType::Show, 'tmdb_id' => 1399]);
        $season = Season::factory()->for($show, 'title')->create(['season_number' => 1]);
        $episode = Episode::factory()->for($show, 'title')->for($season, 'season')->create([
            'tmdb_id' => 63056,
            'season_number' => 1,
            'episode_number' => 1,
        ]);

        $webhookEvent = processWebhook(plexWebhookFixture('webhook_episode_scrobble'));

        expect(Play::where('playable_type', 'episode')->where('playable_id', $episode->id)->exists())->toBeTrue()
            ->and($webhookEvent->processed_at)->not->toBeNull();

        Http::assertNothingSent();
    });

    test('it records a play for a legacy-agent episode scrobble via the show tvdb id encoded in the guid', function () {
        $show = Title::factory()->create(['type' => TitleType::Show, 'tmdb_id' => 1396, 'tvdb_id' => 81189]);
        $season = Season::factory()->for($show, 'title')->create(['season_number' => 1]);
        $episode = Episode::factory()->for($show, 'title')->for($season, 'season')->create([
            'season_number' => 1,
            'episode_number' => 1,
        ]);

        $webhookEvent = processWebhook(plexWebhookFixture('webhook_episode_scrobble_legacy'));

        expect(Play::where('playable_type', 'episode')->where('playable_id', $episode->id)->exists())->toBeTrue()
            ->and($webhookEvent->processed_at)->not->toBeNull();

        Http::assertNothingSent();
    });

    test('it does not attempt an API lookup for an episode when neither a token nor url is configured, even with no usable guid', function () {
        $payload = plexWebhookFixture('webhook_episode_scrobble');
        $payload['Metadata']['Guid'] = [];
        unset($payload['Metadata']['guid']);
        $payload['Metadata']['grandparentTitle'] = 'Unmatched Show';

        $webhookEvent = processWebhook($payload);

        expect(Play::count())->toBe(0)
            ->and($webhookEvent->processed_at)->not->toBeNull()
            ->and($webhookEvent->error)->toContain('Unmatched Show');

        Http::assertNothingSent();
    });
});

test('it fetches guids from the Plex API as a fallback when the payload has none and a token is configured', function () {
    app(IntegrationSettings::class)->setMany([
        'plex.url' => 'http://plex.local:32400',
        'plex.token' => 'plex-token',
        'plex.account_id' => '1',
    ]);

    $show = Title::factory()->create(['type' => TitleType::Show, 'tmdb_id' => 1399]);
    $season = Season::factory()->for($show, 'title')->create(['season_number' => 1]);
    $episode = Episode::factory()->for($show, 'title')->for($season, 'season')->create([
        'tmdb_id' => 603,
        'season_number' => 1,
        'episode_number' => 1,
    ]);

    Http::preventStrayRequests();
    Http::fake([
        '*plex.local:32400/library/metadata/901*' => Http::response(plexWebhookFixture('metadata_movie')),
    ]);

    $payload = plexWebhookFixture('webhook_episode_scrobble');
    $payload['Metadata']['Guid'] = [];
    unset($payload['Metadata']['guid']);

    $webhookEvent = processWebhook($payload);

    expect(Play::where('playable_type', 'episode')->where('playable_id', $episode->id)->exists())->toBeTrue()
        ->and($webhookEvent->processed_at)->not->toBeNull();
});

test('it ignores non-scrobble events', function () {
    Http::preventStrayRequests();

    $webhookEvent = processWebhook(['event' => 'media.play']);

    expect(Play::count())->toBe(0)
        ->and($webhookEvent->processed_at)->not->toBeNull();
});

test('a best-effort Plex API fallback failure does not fail the webhook event', function () {
    Http::preventStrayRequests();

    app(IntegrationSettings::class)->setMany([
        'plex.url' => 'http://plex.local:32400',
        'plex.token' => 'plex-token',
    ]);

    Http::fake([
        '*plex.local:32400/library/metadata/*' => Http::response(null, 500),
    ]);

    $payload = plexWebhookFixture('webhook_episode_scrobble');
    $payload['Metadata']['Guid'] = [];
    unset($payload['Metadata']['guid']);

    $webhookEvent = processWebhook($payload);

    expect($webhookEvent->processed_at)->not->toBeNull()
        ->and($webhookEvent->error)->toContain('Game of Thrones')
        ->and(Play::count())->toBe(0);
});

test('it marks the webhook event failed when a required TMDB lookup throws', function () {
    Http::preventStrayRequests();
    config(['services.tmdb.token' => 'test-token']);
    config(['services.tmdb.base_url' => 'https://api.themoviedb.org/3']);

    Http::fake([
        '*/find/3254641*' => Http::response(null, 500),
    ]);

    $webhookEvent = processWebhook(plexWebhookFixture('webhook_episode_scrobble'));

    expect($webhookEvent->processed_at)->toBeNull()
        ->and($webhookEvent->error)->not->toBeNull();
});

test('a movie scrobble also caches Plex availability from the payload, without an extra API call', function () {
    Http::preventStrayRequests();

    $title = Title::factory()->create(['type' => TitleType::Movie, 'tmdb_id' => 603]);

    processWebhook(plexWebhookFixture('webhook_movie_scrobble'));

    $item = $title->plexItem()->first();

    expect($item)->not->toBeNull()
        ->and($item->rating_key)->toBe('501')
        ->and($item->machine_identifier)->toBe('abcd1234')
        ->and($item->found())->toBeTrue();
});

test('an episode scrobble caches Plex availability for the matched episode', function () {
    Http::preventStrayRequests();

    $show = Title::factory()->create(['type' => TitleType::Show, 'tmdb_id' => 1399]);
    $season = Season::factory()->for($show, 'title')->create(['season_number' => 1]);
    $episode = Episode::factory()->for($show, 'title')->for($season, 'season')->create([
        'tmdb_id' => 63056,
        'season_number' => 1,
        'episode_number' => 1,
    ]);

    processWebhook(plexWebhookFixture('webhook_episode_scrobble'));

    $item = $episode->plexItem()->first();

    expect($item)->not->toBeNull()
        ->and($item->rating_key)->toBe('901')
        ->and($item->machine_identifier)->toBe('abcd1234');
});

test('a library.new event caches availability for an already-imported episode without recording a play', function () {
    Http::preventStrayRequests();

    $show = Title::factory()->create(['type' => TitleType::Show, 'tmdb_id' => 1399]);
    $season = Season::factory()->for($show, 'title')->create(['season_number' => 1]);
    $episode = Episode::factory()->for($show, 'title')->for($season, 'season')->create([
        'tmdb_id' => 63056,
        'season_number' => 1,
        'episode_number' => 1,
    ]);

    $webhookEvent = processWebhook(plexWebhookFixture('webhook_library_new_episode'));

    $item = $episode->plexItem()->first();

    expect($item)->not->toBeNull()
        ->and($item->rating_key)->toBe('901')
        ->and($item->machine_identifier)->toBe('abcd1234')
        ->and(Play::count())->toBe(0)
        ->and($webhookEvent->processed_at)->not->toBeNull();
});

test('a library.new event for an item not yet in the library caches nothing and does not error', function () {
    Http::preventStrayRequests();

    $webhookEvent = processWebhook(plexWebhookFixture('webhook_library_new_episode'));

    expect(PlexItem::count())->toBe(0)
        ->and($webhookEvent->processed_at)->not->toBeNull();
});
