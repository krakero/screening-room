<?php

use App\Enums\WatchProviderType;
use App\Models\Network;
use App\Models\Title;
use App\Models\User;
use App\Models\WatchProvider;
use App\Support\IntegrationSettings;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Queue::fake();
    config(['services.tmdb.region' => 'US']);
    $this->actingAs(User::factory()->create());
});

function attachProvider(Title $title, int $tmdbId, string $name, ?string $logo = null, WatchProviderType $type = WatchProviderType::Flatrate, string $region = 'US'): void
{
    $provider = WatchProvider::factory()->create(['tmdb_id' => $tmdbId, 'name' => $name, 'logo_path' => $logo]);
    $title->watchProviders()->attach($provider, ['type' => $type->value, 'region' => $region]);
}

test('the title page shows a show\'s network logo with its name', function () {
    $title = Title::factory()->show()->create();
    $title->networks()->attach(Network::factory()->create(['tmdb_id' => 19, 'name' => 'FOX', 'logo_path' => '/fox.png']), ['position' => 0]);

    $this->get(route('titles.show', $title))
        ->assertOk()
        ->assertSee('/w92/fox.png', false)
        ->assertSee('FOX');
});

test('the title page shows the network name without a logo when TMDB has none', function () {
    $title = Title::factory()->show()->create();
    $title->networks()->attach(Network::factory()->create(['tmdb_id' => 19, 'name' => 'FOX', 'logo_path' => null]), ['position' => 0]);

    $this->get(route('titles.show', $title))
        ->assertOk()
        ->assertSee('FOX')
        ->assertDontSee('/w92/', false);
});

test('the network logo sits above the title and still shows when where to watch is off', function () {
    app(IntegrationSettings::class)->set('tmdb.show_watch_providers', false);

    $title = Title::factory()->show()->create(['name' => 'Ted Lasso']);
    $title->networks()->attach(Network::factory()->create(['tmdb_id' => 2552, 'name' => 'Apple TV', 'logo_path' => '/apple.png']), ['position' => 0]);

    $this->get(route('titles.show', $title))
        ->assertOk()
        ->assertSee('/w92/apple.png', false)
        ->assertSeeInOrder(['alt="Apple TV"', 'Ted Lasso'], false);
});

test('the title page shows no network for a movie', function () {
    $title = Title::factory()->movie()->create();
    $title->networks()->attach(Network::factory()->create(['tmdb_id' => 19, 'name' => 'FOX', 'logo_path' => '/fox.png']), ['position' => 0]);

    $this->get(route('titles.show', $title))
        ->assertOk()
        ->assertDontSee('FOX');
});

test('the title page groups where to watch into Stream and Free with the JustWatch credit', function () {
    $title = Title::factory()->movie()->create(['watch_providers_checked_at' => now()]);
    attachProvider($title, 8, 'Netflix', type: WatchProviderType::Flatrate);
    attachProvider($title, 300, 'Pluto TV', type: WatchProviderType::Free);
    attachProvider($title, 400, 'Other Region TV', region: 'GB');

    $this->get(route('titles.show', $title))
        ->assertOk()
        ->assertSeeInOrder(['Where to watch', 'Stream', 'Netflix', 'Free', 'Pluto TV', 'Streaming data by JustWatch'])
        ->assertSee('href="https://www.justwatch.com"', false)
        ->assertDontSee('Other Region TV');
});

test('the title page hides where to watch when there are no providers', function () {
    $title = Title::factory()->movie()->create(['watch_providers_checked_at' => now()]);

    $this->get(route('titles.show', $title))
        ->assertOk()
        ->assertDontSee('Where to watch')
        ->assertDontSee('JustWatch');
});

test('the title page hides where to watch when providers have not been checked', function () {
    $title = Title::factory()->movie()->create(['watch_providers_checked_at' => null]);
    attachProvider($title, 8, 'Netflix');

    $this->get(route('titles.show', $title))
        ->assertOk()
        ->assertDontSee('Where to watch');
});

test('the title api includes networks, region-scoped watch providers and the attribution', function () {
    $this->actingAs(User::factory()->create(), 'sanctum');

    $title = Title::factory()->show()->create(['watch_providers_checked_at' => now()]);
    $title->networks()->attach(Network::factory()->create(['tmdb_id' => 19, 'name' => 'FOX', 'logo_path' => '/fox.png']), ['position' => 0]);
    attachProvider($title, 8, 'Netflix', logo: '/n.jpg', type: WatchProviderType::Flatrate);
    attachProvider($title, 400, 'Other Region TV', region: 'GB');

    $response = $this->getJson("/api/v1/titles/{$title->id}")->assertOk();

    expect($response->json('networks'))->toBe([
        ['id' => 19, 'name' => 'FOX', 'logo_url' => config('services.tmdb.image_base_url').'/w92/fox.png'],
    ]);
    expect($response->json('watch_providers'))->toBe([
        ['provider_id' => 8, 'name' => 'Netflix', 'logo_url' => config('services.tmdb.image_base_url').'/w92/n.jpg', 'type' => 'flatrate'],
    ]);
    expect($response->json('justwatch_attribution'))->toContain('JustWatch');
});

test('the title api returns no watch providers for a movie that has not been checked', function () {
    $this->actingAs(User::factory()->create(), 'sanctum');

    $title = Title::factory()->movie()->create(['watch_providers_checked_at' => null]);
    attachProvider($title, 8, 'Netflix');

    $this->getJson("/api/v1/titles/{$title->id}")
        ->assertOk()
        ->assertJsonPath('watch_providers', [])
        ->assertJsonMissingPath('networks');
});

test('with show where to watch off the title page hides where to watch but keeps the network', function () {
    app(IntegrationSettings::class)->set('tmdb.show_watch_providers', false);

    $title = Title::factory()->show()->create(['watch_providers_checked_at' => now()]);
    $title->networks()->attach(Network::factory()->create(['name' => 'FOX']), ['position' => 0]);
    attachProvider($title, 8, 'Netflix');

    $this->get(route('titles.show', $title))
        ->assertOk()
        ->assertSee('alt="FOX"', false)
        ->assertDontSee('Where to watch')
        ->assertDontSee('Netflix');
});

test('with show where to watch off the title api returns no watch providers but keeps networks', function () {
    app(IntegrationSettings::class)->set('tmdb.show_watch_providers', false);
    $this->actingAs(User::factory()->create(), 'sanctum');

    $title = Title::factory()->show()->create(['watch_providers_checked_at' => now()]);
    $title->networks()->attach(Network::factory()->create(['tmdb_id' => 19, 'name' => 'FOX']), ['position' => 0]);
    attachProvider($title, 8, 'Netflix');

    $this->getJson("/api/v1/titles/{$title->id}")
        ->assertOk()
        ->assertJsonPath('watch_providers', [])
        ->assertJsonPath('networks.0.name', 'FOX');
});
