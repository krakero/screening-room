<?php

use App\Actions\Tmdb\ImportMovie;
use App\Actions\Tmdb\ImportShow;
use App\Enums\TitleType;
use App\Enums\WatchProviderType;
use App\Jobs\ImportTitle;
use App\Models\Network;
use App\Models\Title;
use App\Models\WatchProvider;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config(['services.tmdb.token' => 'test-token']);
    config(['services.tmdb.base_url' => 'https://api.themoviedb.org/3']);
    config(['services.tmdb.region' => 'US']);

    Http::preventStrayRequests();
});

function providerFixture(string $name): array
{
    return json_decode(file_get_contents(base_path("tests/Fixtures/tmdb/{$name}.json")), true);
}

function fakeShowWith(array $extra): void
{
    Bus::fake();

    Http::fake(['*/tv/1396*' => Http::response(array_merge(providerFixture('show_1396'), $extra))]);
}

test('a show import saves its networks in TMDB order', function () {
    fakeShowWith(providerFixture('watch_providers_and_networks'));

    $title = app(ImportShow::class)->handle(1396);

    expect($title->networks->map(fn (Network $network): array => [$network->tmdb_id, $network->name, $network->logo_path, $network->origin_country, $network->pivot->position])->all())->toBe([
        [174, 'AMC', '/pmvRmATOCaDykE6JrVoeYxlFHw3.png', 'US', 0],
        [19, 'FOX', null, 'US', 1],
    ]);
});

test('re-importing a show updates the network and drops ones TMDB no longer lists', function () {
    Bus::fake();
    Http::fake(['*/tv/1396*' => Http::sequence()
        ->push(array_merge(providerFixture('show_1396'), providerFixture('watch_providers_and_networks')))
        ->push(array_merge(providerFixture('show_1396'), ['networks' => [
            ['id' => 19, 'name' => 'FOX Renamed', 'logo_path' => null, 'origin_country' => 'US'],
        ]]))]);

    $title = app(ImportShow::class)->handle(1396);
    $title = app(ImportShow::class)->handle(1396);

    expect($title->networks->pluck('name')->all())->toBe(['FOX Renamed'])
        ->and(Network::count())->toBe(2);
});

test('a movie import does not save networks', function () {
    Http::fake(['*/movie/603*' => Http::response(array_merge(providerFixture('movie_603'), providerFixture('watch_providers_and_networks')))]);

    $title = app(ImportMovie::class)->handle(603);

    expect($title->networks)->toBeEmpty()
        ->and($title->watchProviders)->not->toBeEmpty();
});

test('only the configured region streaming and free providers are saved, rent and buy are ignored', function () {
    fakeShowWith(providerFixture('watch_providers_and_networks'));

    $title = app(ImportShow::class)->handle(1396);

    $saved = $title->watchProviders->map(fn (WatchProvider $p): string => "{$p->tmdb_id}:{$p->pivot->type}:{$p->pivot->region}")->sort()->values()->all();

    expect($saved)->toBe(['15:flatrate:US', '300:ads:US', '73:ads:US', '73:free:US', '8:flatrate:US'])
        ->and(WatchProvider::count())->toBe(4)
        ->and($title->watch_providers_checked_at)->not->toBeNull();
});

test('channel variants are never stored', function () {
    fakeShowWith(['watch/providers' => ['results' => ['US' => ['flatrate' => [
        ['logo_path' => null, 'provider_id' => 8, 'provider_name' => 'Netflix', 'display_priority' => 0],
        ['logo_path' => null, 'provider_id' => 119, 'provider_name' => 'HBO Max Amazon Channel', 'display_priority' => 1],
        ['logo_path' => null, 'provider_id' => 120, 'provider_name' => ' Starz Apple TV channel ', 'display_priority' => 2],
    ]]]]]);

    $title = app(ImportShow::class)->handle(1396);

    expect($title->watchProviders->pluck('name')->all())->toBe(['Netflix'])
        ->and(WatchProvider::pluck('tmdb_id')->all())->toBe([8]);
});

test('isChannelVariant matches names ending in Channel, case-insensitively and trimmed', function () {
    expect(WatchProvider::isChannelVariant('HBO Max Amazon Channel'))->toBeTrue()
        ->and(WatchProvider::isChannelVariant('  Paramount+ Roku Premium channel '))->toBeTrue()
        ->and(WatchProvider::isChannelVariant('Netflix'))->toBeFalse()
        ->and(WatchProvider::isChannelVariant('Channel 4 Player'))->toBeFalse();
});

test('a provider is upserted by tmdb id and shared between titles', function () {
    Bus::fake();
    Http::fake(['*/tv/1396*' => Http::sequence()
        ->push(array_merge(providerFixture('show_1396'), providerFixture('watch_providers_and_networks')))
        ->push(array_merge(providerFixture('show_1396'), ['watch/providers' => ['results' => ['US' => ['flatrate' => [
            ['logo_path' => '/new.jpg', 'provider_id' => 8, 'provider_name' => 'Netflix Renamed', 'display_priority' => 9],
        ]]]]]))]);

    app(ImportShow::class)->handle(1396);
    $other = Title::factory()->create();
    $netflix = WatchProvider::where('tmdb_id', 8)->firstOrFail();
    $other->watchProviders()->attach($netflix, ['type' => 'flatrate', 'region' => 'US']);

    app(ImportShow::class)->handle(1396);

    expect($netflix->fresh()->only(['name', 'logo_path', 'display_priority']))->toBe(['name' => 'Netflix Renamed', 'logo_path' => '/new.jpg', 'display_priority' => 9])
        ->and(WatchProvider::where('tmdb_id', 8)->count())->toBe(1)
        ->and($netflix->titles()->count())->toBe(2);
});

test('re-syncing replaces this regions rows and keeps other regions', function () {
    Bus::fake();
    Http::fake(['*/tv/1396*' => Http::sequence()
        ->push(array_merge(providerFixture('show_1396'), providerFixture('watch_providers_and_networks')))
        ->push(array_merge(providerFixture('show_1396'), ['watch/providers' => ['results' => ['US' => ['flatrate' => [
            ['logo_path' => null, 'provider_id' => 350, 'provider_name' => 'Apple TV+', 'display_priority' => 2],
        ]]]]]))]);

    $title = app(ImportShow::class)->handle(1396);

    $title->watchProviders()->attach(WatchProvider::factory()->create(['tmdb_id' => 999]), ['type' => 'flatrate', 'region' => 'GB']);

    $title = app(ImportShow::class)->handle(1396);

    $inRegion = fn (string $region): array => $title->watchProviders()->wherePivot('region', $region)->pluck('tmdb_id')->all();

    expect($inRegion('US'))->toBe([350])
        ->and($inRegion('GB'))->toBe([999]);
});

test('an empty provider list still marks the title as checked', function () {
    fakeShowWith([]);

    $title = app(ImportShow::class)->handle(1396);

    expect($title->watchProviders)->toBeEmpty()
        ->and($title->watch_providers_checked_at)->not->toBeNull();
});

test('streamingProviders dedupes by provider with flatrate winning and sorts by display priority', function () {
    $title = Title::factory()->create();
    $make = function (int $tmdbId, string $name, WatchProviderType $type, int $priority, string $region = 'US') use ($title): void {
        $provider = WatchProvider::firstOrCreate(['tmdb_id' => $tmdbId], ['name' => $name, 'display_priority' => $priority]);
        $title->watchProviders()->attach($provider, ['type' => $type->value, 'region' => $region]);
    };

    $make(73, 'Tubi', WatchProviderType::Ads, 1);
    $make(73, 'Tubi', WatchProviderType::Flatrate, 1);
    $make(8, 'Netflix', WatchProviderType::Flatrate, 0);
    $make(15, 'Hulu', WatchProviderType::Free, 5);
    $make(99, 'Sky', WatchProviderType::Flatrate, 0, 'GB');

    $providers = $title->fresh()->streamingProviders();

    expect($providers->pluck('tmdb_id')->all())->toBe([8, 73, 15])
        ->and($providers->firstWhere('tmdb_id', 73)->pivot->type)->toBe(WatchProviderType::Flatrate)
        ->and($providers->firstWhere('tmdb_id', 15)->pivot->type)->toBe(WatchProviderType::Free);
});

test('availableOn takes a watch provider id and only matches titles on it in the region', function () {
    $onNetflix = Title::factory()->create();
    $inGb = Title::factory()->create();
    Title::factory()->create();

    $netflix = WatchProvider::factory()->create(['tmdb_id' => 8]);
    $onNetflix->watchProviders()->attach($netflix, ['type' => 'flatrate', 'region' => 'US']);
    $inGb->watchProviders()->attach($netflix, ['type' => 'flatrate', 'region' => 'GB']);

    expect(Title::availableOn($netflix->id)->pluck('id')->all())->toBe([$onNetflix->id])
        ->and(Title::availableOn($netflix->id, 'GB')->pluck('id')->all())->toBe([$inGb->id])
        ->and(Title::availableOn($netflix->id + 1)->count())->toBe(0);
});

test('onNetwork matches titles on the network by its id', function () {
    $onAmc = Title::factory()->create();
    Title::factory()->create();
    $amc = Network::factory()->create(['tmdb_id' => 174]);
    $onAmc->networks()->attach($amc, ['position' => 0]);

    expect(Title::onNetwork($amc->id)->pluck('id')->all())->toBe([$onAmc->id])
        ->and(Title::onNetwork($amc->id + 1)->count())->toBe(0);
});

test('logo urls are built from the image base url', function () {
    config(['services.tmdb.image_base_url' => 'https://img.test/t/p']);

    expect(WatchProvider::factory()->make(['logo_path' => '/a.jpg'])->logoUrl())->toBe('https://img.test/t/p/w92/a.jpg')
        ->and(Network::factory()->make(['logo_path' => '/n.png'])->logoUrl())->toBe('https://img.test/t/p/w92/n.png')
        ->and(Network::factory()->make(['logo_path' => null])->logoUrl())->toBeNull()
        ->and(Title::networkLogoUrl('/n.png'))->toBe('https://img.test/t/p/w92/n.png');
});

test('tmdb:refresh --providers-missing targets titles never checked, up to the limit', function () {
    Bus::fake();

    $missing = Title::factory()->count(3)->create(['type' => TitleType::Movie, 'tmdb_synced_at' => now(), 'watch_providers_checked_at' => null]);
    $checked = Title::factory()->create(['type' => TitleType::Movie, 'tmdb_synced_at' => now(), 'watch_providers_checked_at' => now()]);

    $this->artisan('tmdb:refresh --providers-missing --limit=2')->assertSuccessful();

    Bus::assertDispatchedTimes(ImportTitle::class, 2);
    Bus::assertNotDispatched(ImportTitle::class, fn (ImportTitle $job): bool => $job->tmdbId === $checked->tmdb_id);
});
