<?php

use App\Models\User;
use App\Support\IntegrationSettings;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

test('guests are redirected to the login page', function () {
    $response = $this->get(route('settings.integrations.seerr'));

    $response->assertRedirect(route('login'));
});

test('the seerr page can be rendered', function () {
    $this->actingAs(User::factory()->create());

    $response = $this->get(route('settings.integrations.seerr'));

    $response->assertOk();
    $response->assertSee('Seerr');
    $response->assertSee('Webhook URL');
    $response->assertDontSee('@if');
});

test('the feature list reflects what is configured', function () {
    $this->actingAs(User::factory()->create());

    $component = Livewire::test('pages::settings.integrations.seerr');

    $component->assertSee('needs: Server URL, API key')
        ->assertDontSee('@if');

    $component->set('seerrUrl', 'http://seerr.local:5055')
        ->set('seerrApiKey', 'secret-key')
        ->call('saveSeerr');

    $component->assertSee('Ready')
        ->assertDontSee('needs: Server URL, API key')
        ->assertDontSee('@if');
});

test('seerr settings can be saved', function () {
    $this->actingAs(User::factory()->create());

    Livewire::test('pages::settings.integrations.seerr')
        ->set('seerrUrl', 'http://seerr.local:5055')
        ->set('seerrApiKey', 'secret-key')
        ->call('saveSeerr')
        ->assertHasNoErrors();

    $settings = app(IntegrationSettings::class);

    expect($settings->get('seerr.url'))->toBe('http://seerr.local:5055')
        ->and($settings->get('seerr.api_key'))->toBe('secret-key');
});

test('leaving the api key field blank keeps the existing value', function () {
    $this->actingAs(User::factory()->create());

    app(IntegrationSettings::class)->set('seerr.api_key', 'original-key');

    Livewire::test('pages::settings.integrations.seerr')
        ->set('seerrUrl', 'http://seerr.local:5055')
        ->set('seerrApiKey', '')
        ->call('saveSeerr')
        ->assertHasNoErrors();

    expect(app(IntegrationSettings::class)->get('seerr.api_key'))->toBe('original-key');
});

test('the shared arr webhook secret can be regenerated from the seerr page', function () {
    $this->actingAs(User::factory()->create());

    $component = Livewire::test('pages::settings.integrations.seerr');

    $original = $component->get('arrWebhookUrl');

    $component->call('regenerateArrWebhookSecret');

    expect($component->get('arrWebhookUrl'))->not->toBe($original);
});

test('testing the connection saves the unsaved fields first', function () {
    $this->actingAs(User::factory()->create());
    Http::fake(['*' => Http::response(['version' => '1.0'])]);

    Livewire::test('pages::settings.integrations.seerr')
        ->set('seerrUrl', 'http://seerr.local:5055')
        ->set('seerrApiKey', 'fresh-key')
        ->call('testSeerr')
        ->assertHasNoErrors()
        ->assertSet('seerrApiKey', '')
        ->assertSet('seerrApiKeyConfigured', true);

    $settings = app(IntegrationSettings::class);

    expect($settings->get('seerr.url'))->toBe('http://seerr.local:5055')
        ->and($settings->get('seerr.api_key'))->toBe('fresh-key');

    Http::assertSent(fn ($request) => str_starts_with($request->url(), 'http://seerr.local:5055'));
});

test('testing the connection with a blank api key keeps the saved key', function () {
    $this->actingAs(User::factory()->create());
    Http::fake(['*' => Http::response(['version' => '1.0'])]);

    app(IntegrationSettings::class)->set('seerr.api_key', 'original-key');

    Livewire::test('pages::settings.integrations.seerr')
        ->set('seerrUrl', 'http://seerr.local:5055')
        ->set('seerrApiKey', '')
        ->call('testSeerr')
        ->assertHasNoErrors();

    expect(app(IntegrationSettings::class)->get('seerr.api_key'))->toBe('original-key');
    Http::assertSentCount(1);
});

test('a validation error on test saves nothing and runs no test', function () {
    $this->actingAs(User::factory()->create());
    Http::fake();

    Livewire::test('pages::settings.integrations.seerr')
        ->set('seerrUrl', 'not-a-url')
        ->set('seerrApiKey', 'fresh-key')
        ->call('testSeerr')
        ->assertHasErrors('seerrUrl');

    expect(app(IntegrationSettings::class)->get('seerr.api_key'))->toBeNull();
    Http::assertNothingSent();
});
