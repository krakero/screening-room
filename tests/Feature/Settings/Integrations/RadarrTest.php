<?php

use App\Models\User;
use App\Support\IntegrationSettings;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

test('guests are redirected to the login page', function () {
    $response = $this->get(route('settings.integrations.radarr'));

    $response->assertRedirect(route('login'));
});

test('the radarr page can be rendered', function () {
    $this->actingAs(User::factory()->create());

    $response = $this->get(route('settings.integrations.radarr'));

    $response->assertOk();
    $response->assertSee('Radarr');
    $response->assertSee('Webhook URL');
    $response->assertDontSee('@if');
});

test('the feature list reflects what is configured', function () {
    $this->actingAs(User::factory()->create());

    $component = Livewire::test('pages::settings.integrations.radarr');

    $component->assertSee('needs: Server URL, API key')
        ->assertDontSee('@if');

    $component->set('radarrUrl', 'http://radarr.local:7878')
        ->set('radarrApiKey', 'secret-key')
        ->call('saveRadarr');

    $component->assertSee('Ready')
        ->assertDontSee('needs: Server URL, API key')
        ->assertDontSee('@if');
});

test('radarr settings can be saved', function () {
    $this->actingAs(User::factory()->create());

    Livewire::test('pages::settings.integrations.radarr')
        ->set('radarrUrl', 'http://radarr.local:7878')
        ->set('radarrApiKey', 'secret-key')
        ->call('saveRadarr')
        ->assertHasNoErrors();

    $settings = app(IntegrationSettings::class);

    expect($settings->get('radarr.url'))->toBe('http://radarr.local:7878')
        ->and($settings->get('radarr.api_key'))->toBe('secret-key');
});

test('leaving the api key field blank keeps the existing value', function () {
    $this->actingAs(User::factory()->create());

    app(IntegrationSettings::class)->set('radarr.api_key', 'original-key');

    Livewire::test('pages::settings.integrations.radarr')
        ->set('radarrUrl', 'http://radarr.local:7878')
        ->set('radarrApiKey', '')
        ->call('saveRadarr')
        ->assertHasNoErrors();

    expect(app(IntegrationSettings::class)->get('radarr.api_key'))->toBe('original-key');
});

test('the shared arr webhook secret can be regenerated from the radarr page', function () {
    $this->actingAs(User::factory()->create());

    $component = Livewire::test('pages::settings.integrations.radarr');

    $original = $component->get('arrWebhookUrl');

    $component->call('regenerateArrWebhookSecret');

    expect($component->get('arrWebhookUrl'))->not->toBe($original);
});

test('testing the connection saves the unsaved fields first', function () {
    $this->actingAs(User::factory()->create());
    Http::fake(['*' => Http::response(['version' => '1.0'])]);

    Livewire::test('pages::settings.integrations.radarr')
        ->set('radarrUrl', 'http://radarr.local:7878')
        ->set('radarrApiKey', 'fresh-key')
        ->call('testRadarr')
        ->assertHasNoErrors()
        ->assertSet('radarrApiKey', '')
        ->assertSet('radarrApiKeyConfigured', true);

    $settings = app(IntegrationSettings::class);

    expect($settings->get('radarr.url'))->toBe('http://radarr.local:7878')
        ->and($settings->get('radarr.api_key'))->toBe('fresh-key');

    Http::assertSent(fn ($request) => str_starts_with($request->url(), 'http://radarr.local:7878'));
});

test('testing the connection with a blank api key keeps the saved key', function () {
    $this->actingAs(User::factory()->create());
    Http::fake(['*' => Http::response(['version' => '1.0'])]);

    app(IntegrationSettings::class)->set('radarr.api_key', 'original-key');

    Livewire::test('pages::settings.integrations.radarr')
        ->set('radarrUrl', 'http://radarr.local:7878')
        ->set('radarrApiKey', '')
        ->call('testRadarr')
        ->assertHasNoErrors();

    expect(app(IntegrationSettings::class)->get('radarr.api_key'))->toBe('original-key');
    Http::assertSentCount(1);
});

test('a validation error on test saves nothing and runs no test', function () {
    $this->actingAs(User::factory()->create());
    Http::fake();

    Livewire::test('pages::settings.integrations.radarr')
        ->set('radarrUrl', 'not-a-url')
        ->set('radarrApiKey', 'fresh-key')
        ->call('testRadarr')
        ->assertHasErrors('radarrUrl');

    expect(app(IntegrationSettings::class)->get('radarr.api_key'))->toBeNull();
    Http::assertNothingSent();
});
