<?php

use App\Models\IntegrationSetting;
use App\Models\User;
use App\Support\IntegrationSettings;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

beforeEach(function () {
    Http::preventStrayRequests();
});

test('guests are redirected to the login page', function () {
    $response = $this->get(route('settings.integrations.tmdb'));

    $response->assertRedirect(route('login'));
});

test('the tmdb page can be rendered', function () {
    $this->actingAs(User::factory()->create());

    $response = $this->get(route('settings.integrations.tmdb'));

    $response->assertOk();
    $response->assertSee('TMDB');
    $response->assertDontSee('@if');
});

test('the feature list reflects whether the token is configured', function () {
    $this->actingAs(User::factory()->create());

    config(['services.tmdb.token' => null]);
    $this->get(route('settings.integrations.tmdb'))
        ->assertSee('needs: API Read Access Token')
        ->assertDontSee('@if');

    config(['services.tmdb.token' => 'test-token']);
    $this->get(route('settings.integrations.tmdb'))
        ->assertSee('Ready')
        ->assertDontSee('needs: API Read Access Token')
        ->assertDontSee('@if');
});

test('a token saved here is stored encrypted and overrides the env token', function () {
    Http::fake([
        '*/authentication*' => Http::response(['success' => true]),
    ]);

    $this->actingAs(User::factory()->create());

    Livewire::test('pages::settings.integrations.tmdb')
        ->set('token', 'db-token')
        ->set('region', 'GB')
        ->call('saveTmdb')
        ->assertHasNoErrors();

    expect(app(IntegrationSettings::class)->get('tmdb.token'))->toBe('db-token')
        ->and(app(IntegrationSettings::class)->get('tmdb.region'))->toBe('GB');

    $row = IntegrationSetting::where('key', 'tmdb.token')->sole();
    expect($row->getRawOriginal('value'))->not->toContain('db-token');
});

test('the test connection button pings tmdb', function () {
    Http::fake([
        '*/authentication*' => Http::response(['success' => true]),
    ]);

    $this->actingAs(User::factory()->create());
    app(IntegrationSettings::class)->set('tmdb.token', 'db-token');

    Livewire::test('pages::settings.integrations.tmdb')
        ->call('testTmdb');

    Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer db-token'));
});

test('testing the connection saves the typed token first and then pings tmdb', function () {
    Http::fake([
        '*/authentication*' => Http::response(['success' => true]),
    ]);

    $this->actingAs(User::factory()->create());

    Livewire::test('pages::settings.integrations.tmdb')
        ->set('token', 'typed-token')
        ->set('region', 'GB')
        ->call('testTmdb')
        ->assertHasNoErrors()
        ->assertSet('token', '');

    expect(app(IntegrationSettings::class)->get('tmdb.token'))->toBe('typed-token')
        ->and(app(IntegrationSettings::class)->get('tmdb.region'))->toBe('GB');
    Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer typed-token'));
});

test('testing the connection with a blank token keeps the saved token', function () {
    Http::fake([
        '*/authentication*' => Http::response(['success' => true]),
    ]);

    $this->actingAs(User::factory()->create());
    app(IntegrationSettings::class)->set('tmdb.token', 'db-token');

    Livewire::test('pages::settings.integrations.tmdb')
        ->set('token', '')
        ->call('testTmdb');

    expect(app(IntegrationSettings::class)->get('tmdb.token'))->toBe('db-token');
    Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer db-token'));
});

test('a validation error on test saves nothing and runs no test', function () {
    Http::fake();

    $this->actingAs(User::factory()->create());

    Livewire::test('pages::settings.integrations.tmdb')
        ->set('token', 'typed-token')
        ->set('region', 'GBR')
        ->call('testTmdb')
        ->assertHasErrors(['region']);

    expect(app(IntegrationSettings::class)->get('tmdb.token'))->toBeNull();
    Http::assertNothingSent();
});

test('show where to watch defaults on and can be switched off and saved', function () {
    $this->actingAs(User::factory()->create());

    Livewire::test('pages::settings.integrations.tmdb')
        ->assertSet('showWatchProviders', true)
        ->set('showWatchProviders', false)
        ->call('saveTmdb')
        ->assertHasNoErrors();

    expect(app(IntegrationSettings::class)->get('tmdb.show_watch_providers'))->toBeFalse();

    Livewire::test('pages::settings.integrations.tmdb')
        ->assertSet('showWatchProviders', false);
});

test('testing the connection also saves the show where to watch switch', function () {
    Http::fake(['*/authentication*' => Http::response(['success' => true])]);
    $this->actingAs(User::factory()->create());

    Livewire::test('pages::settings.integrations.tmdb')
        ->set('showWatchProviders', false)
        ->call('testTmdb');

    expect(app(IntegrationSettings::class)->get('tmdb.show_watch_providers'))->toBeFalse();
});
