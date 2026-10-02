<?php

use App\Models\User;
use App\Support\IntegrationSettings;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

test('guests are redirected to the login page', function () {
    $response = $this->get(route('settings.integrations.pushover'));

    $response->assertRedirect(route('login'));
});

test('the pushover page can be rendered', function () {
    $this->actingAs(User::factory()->create());

    $response = $this->get(route('settings.integrations.pushover'));

    $response->assertOk();
    $response->assertSee('Pushover');
    $response->assertDontSee('@if');
});

test('the feature list reflects what is configured', function () {
    $this->actingAs(User::factory()->create());

    $component = Livewire::test('pages::settings.integrations.pushover');

    $component->assertSee('needs: User key, App token')
        ->assertDontSee('@if');

    $component->set('pushoverUserKey', 'user-key')
        ->set('pushoverAppToken', 'app-token')
        ->call('savePushover');

    $component->assertSee('Ready')
        ->assertDontSee('needs: User key, App token')
        ->assertDontSee('@if');
});

test('pushover settings can be saved', function () {
    $this->actingAs(User::factory()->create());

    Livewire::test('pages::settings.integrations.pushover')
        ->set('pushoverUserKey', 'user-key')
        ->set('pushoverAppToken', 'app-token')
        ->call('savePushover')
        ->assertHasNoErrors();

    $settings = app(IntegrationSettings::class);

    expect($settings->get('pushover.user_key'))->toBe('user-key')
        ->and($settings->get('pushover.app_token'))->toBe('app-token');
});

test('leaving pushover fields blank keeps the existing values', function () {
    $this->actingAs(User::factory()->create());

    app(IntegrationSettings::class)->setMany([
        'pushover.user_key' => 'original-user-key',
        'pushover.app_token' => 'original-app-token',
    ]);

    Livewire::test('pages::settings.integrations.pushover')
        ->set('pushoverUserKey', '')
        ->set('pushoverAppToken', '')
        ->call('savePushover')
        ->assertHasNoErrors();

    $settings = app(IntegrationSettings::class);

    expect($settings->get('pushover.user_key'))->toBe('original-user-key')
        ->and($settings->get('pushover.app_token'))->toBe('original-app-token');
});

test('testing the connection saves the typed keys first and then validates them', function () {
    Http::fake(['*' => Http::response(['status' => 1])]);

    $this->actingAs(User::factory()->create());

    Livewire::test('pages::settings.integrations.pushover')
        ->set('pushoverUserKey', 'typed-user')
        ->set('pushoverAppToken', 'typed-token')
        ->call('testPushover')
        ->assertHasNoErrors()
        ->assertSet('pushoverUserKey', '')
        ->assertSet('pushoverAppToken', '');

    $settings = app(IntegrationSettings::class);

    expect($settings->get('pushover.user_key'))->toBe('typed-user')
        ->and($settings->get('pushover.app_token'))->toBe('typed-token');
    Http::assertSentCount(1);
});

test('testing the connection with blank fields keeps the saved values', function () {
    Http::fake(['*' => Http::response(['status' => 1])]);

    $this->actingAs(User::factory()->create());
    app(IntegrationSettings::class)->setMany([
        'pushover.user_key' => 'original-user-key',
        'pushover.app_token' => 'original-app-token',
    ]);

    Livewire::test('pages::settings.integrations.pushover')
        ->call('testPushover');

    $settings = app(IntegrationSettings::class);

    expect($settings->get('pushover.user_key'))->toBe('original-user-key')
        ->and($settings->get('pushover.app_token'))->toBe('original-app-token');
    Http::assertSentCount(1);
});
