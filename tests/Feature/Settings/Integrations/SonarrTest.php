<?php

use App\Models\User;
use App\Support\IntegrationSettings;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

test('guests are redirected to the login page', function () {
    $response = $this->get(route('settings.integrations.sonarr'));

    $response->assertRedirect(route('login'));
});

test('the sonarr page can be rendered', function () {
    $this->actingAs(User::factory()->create());

    $response = $this->get(route('settings.integrations.sonarr'));

    $response->assertOk();
    $response->assertSee('Sonarr');
    $response->assertSee('Webhook URL');
    $response->assertDontSee('@if');
});

test('the feature list reflects what is configured', function () {
    $this->actingAs(User::factory()->create());

    $component = Livewire::test('pages::settings.integrations.sonarr');

    $component->assertSee('needs: Server URL, API key')
        ->assertDontSee('@if');

    $component->set('sonarrUrl', 'http://sonarr.local:8989')
        ->set('sonarrApiKey', 'secret-key')
        ->call('saveSonarr');

    $component->assertSee('Ready')
        ->assertDontSee('needs: Server URL, API key')
        ->assertDontSee('@if');
});

test('sonarr settings can be saved', function () {
    $this->actingAs(User::factory()->create());

    Livewire::test('pages::settings.integrations.sonarr')
        ->set('sonarrUrl', 'http://sonarr.local:8989')
        ->set('sonarrApiKey', 'secret-key')
        ->call('saveSonarr')
        ->assertHasNoErrors();

    $settings = app(IntegrationSettings::class);

    expect($settings->get('sonarr.url'))->toBe('http://sonarr.local:8989')
        ->and($settings->get('sonarr.api_key'))->toBe('secret-key');
});

test('leaving the api key field blank keeps the existing value', function () {
    $this->actingAs(User::factory()->create());

    app(IntegrationSettings::class)->set('sonarr.api_key', 'original-key');

    Livewire::test('pages::settings.integrations.sonarr')
        ->set('sonarrUrl', 'http://sonarr.local:8989')
        ->set('sonarrApiKey', '')
        ->call('saveSonarr')
        ->assertHasNoErrors();

    expect(app(IntegrationSettings::class)->get('sonarr.api_key'))->toBe('original-key');
});

test('the shared arr webhook secret can be regenerated from the sonarr page', function () {
    $this->actingAs(User::factory()->create());

    $component = Livewire::test('pages::settings.integrations.sonarr');

    $original = $component->get('arrWebhookUrl');

    $component->call('regenerateArrWebhookSecret');

    expect($component->get('arrWebhookUrl'))->not->toBe($original);
});

test('testing the connection saves the unsaved fields first', function () {
    $this->actingAs(User::factory()->create());
    Http::fake(['*' => Http::response(['version' => '1.0'])]);

    Livewire::test('pages::settings.integrations.sonarr')
        ->set('sonarrUrl', 'http://sonarr.local:8989')
        ->set('sonarrApiKey', 'fresh-key')
        ->call('testSonarr')
        ->assertHasNoErrors()
        ->assertSet('sonarrApiKey', '')
        ->assertSet('sonarrApiKeyConfigured', true);

    $settings = app(IntegrationSettings::class);

    expect($settings->get('sonarr.url'))->toBe('http://sonarr.local:8989')
        ->and($settings->get('sonarr.api_key'))->toBe('fresh-key');

    Http::assertSent(fn ($request) => str_starts_with($request->url(), 'http://sonarr.local:8989'));
});

test('testing the connection with a blank api key keeps the saved key', function () {
    $this->actingAs(User::factory()->create());
    Http::fake(['*' => Http::response(['version' => '1.0'])]);

    app(IntegrationSettings::class)->set('sonarr.api_key', 'original-key');

    Livewire::test('pages::settings.integrations.sonarr')
        ->set('sonarrUrl', 'http://sonarr.local:8989')
        ->set('sonarrApiKey', '')
        ->call('testSonarr')
        ->assertHasNoErrors();

    expect(app(IntegrationSettings::class)->get('sonarr.api_key'))->toBe('original-key');
    Http::assertSentCount(1);
});

test('a validation error on test saves nothing and runs no test', function () {
    $this->actingAs(User::factory()->create());
    Http::fake();

    Livewire::test('pages::settings.integrations.sonarr')
        ->set('sonarrUrl', 'not-a-url')
        ->set('sonarrApiKey', 'fresh-key')
        ->call('testSonarr')
        ->assertHasErrors('sonarrUrl');

    expect(app(IntegrationSettings::class)->get('sonarr.api_key'))->toBeNull();
    Http::assertNothingSent();
});
