<?php

use App\Models\User;
use App\Support\IntegrationSettings;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

test('guests are redirected to the login page', function () {
    $response = $this->get(route('settings.integrations.mdblist'));

    $response->assertRedirect(route('login'));
});

test('the mdblist page can be rendered', function () {
    $this->actingAs(User::factory()->create());

    $response = $this->get(route('settings.integrations.mdblist'));

    $response->assertOk();
    $response->assertSee('MDBList');
    $response->assertDontSee('@if');
});

test('the feature list reflects what is configured', function () {
    $this->actingAs(User::factory()->create());

    $component = Livewire::test('pages::settings.integrations.mdblist');

    $component->assertSee('needs: API key')
        ->assertDontSee('@if');

    $component->set('mdblistApiKey', 'test-key')
        ->call('saveMdbList');

    $component->assertSee('Ready')
        ->assertDontSee('needs: API key')
        ->assertDontSee('@if');
});

test('mdblist settings can be saved', function () {
    $this->actingAs(User::factory()->create());

    Livewire::test('pages::settings.integrations.mdblist')
        ->set('mdblistApiKey', 'test-key')
        ->call('saveMdbList')
        ->assertHasNoErrors();

    expect(app(IntegrationSettings::class)->get('mdblist.api_key'))->toBe('test-key');
});

test('leaving the api key field blank keeps the existing value', function () {
    $this->actingAs(User::factory()->create());

    app(IntegrationSettings::class)->set('mdblist.api_key', 'original-key');

    Livewire::test('pages::settings.integrations.mdblist')
        ->set('mdblistApiKey', '')
        ->call('saveMdbList')
        ->assertHasNoErrors();

    expect(app(IntegrationSettings::class)->get('mdblist.api_key'))->toBe('original-key');
});

test('testing the connection saves the typed key first and then pings mdblist', function () {
    Http::fake(['*' => Http::response(['user_id' => 1])]);

    $this->actingAs(User::factory()->create());

    Livewire::test('pages::settings.integrations.mdblist')
        ->set('mdblistApiKey', 'typed-key')
        ->call('testMdbList')
        ->assertHasNoErrors()
        ->assertSet('mdblistApiKey', '')
        ->assertSet('mdblistApiKeyConfigured', true);

    expect(app(IntegrationSettings::class)->get('mdblist.api_key'))->toBe('typed-key');
    Http::assertSentCount(1);
});

test('testing the connection with a blank key keeps the saved key', function () {
    Http::fake(['*' => Http::response(['user_id' => 1])]);

    $this->actingAs(User::factory()->create());
    app(IntegrationSettings::class)->set('mdblist.api_key', 'original-key');

    Livewire::test('pages::settings.integrations.mdblist')
        ->set('mdblistApiKey', '')
        ->call('testMdbList');

    expect(app(IntegrationSettings::class)->get('mdblist.api_key'))->toBe('original-key');
    Http::assertSentCount(1);
});
