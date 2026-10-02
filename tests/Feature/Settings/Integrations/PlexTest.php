<?php

use App\Jobs\SyncPlexLibrary;
use App\Models\IntegrationSetting;
use App\Models\PlexLibraryItem;
use App\Models\User;
use App\Support\IntegrationSettings;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

beforeEach(function () {
    Http::preventStrayRequests();
});

test('guests are redirected to the login page', function () {
    $response = $this->get(route('settings.integrations.plex'));

    $response->assertRedirect(route('login'));
});

test('the plex page can be rendered', function () {
    $this->actingAs(User::factory()->create());

    $response = $this->get(route('settings.integrations.plex'));

    $response->assertOk();
    $response->assertSee('Plex');
    $response->assertSee('How Plex scrobbling works');
    $response->assertDontSee('@if');
});

test('a plex webhook secret is generated on first visit', function () {
    $this->actingAs(User::factory()->create());

    expect(IntegrationSetting::where('key', 'plex.webhook_secret')->exists())->toBeFalse();

    Livewire::test('pages::settings.integrations.plex');

    expect(IntegrationSetting::where('key', 'plex.webhook_secret')->exists())->toBeTrue();
});

test('plex settings can be saved', function () {
    $this->actingAs(User::factory()->create());

    Http::fake([
        '*plex.local:32400/accounts*' => Http::response(['MediaContainer' => ['Account' => []]]),
    ]);

    Livewire::test('pages::settings.integrations.plex')
        ->set('plexUrl', 'http://plex.local:32400')
        ->set('plexToken', 'secret-token')
        ->set('plexAccountId', '12345')
        ->call('savePlex')
        ->assertHasNoErrors();

    $settings = app(IntegrationSettings::class);

    expect($settings->get('plex.url'))->toBe('http://plex.local:32400')
        ->and($settings->get('plex.account_id'))->toBe('12345')
        ->and($settings->get('plex.token'))->toBe('secret-token');
});

test('leaving the token field blank keeps the existing value', function () {
    $this->actingAs(User::factory()->create());

    app(IntegrationSettings::class)->set('plex.token', 'original-token');

    Livewire::test('pages::settings.integrations.plex')
        ->set('plexUrl', 'http://plex.local:32400')
        ->set('plexToken', '')
        ->call('savePlex')
        ->assertHasNoErrors();

    expect(app(IntegrationSettings::class)->get('plex.token'))->toBe('original-token');
});

test('the feature list reflects what is configured', function () {
    $this->actingAs(User::factory()->create());

    Http::fake([
        '*plex.local:32400/accounts*' => Http::response(['MediaContainer' => ['Account' => []]]),
    ]);

    $component = Livewire::test('pages::settings.integrations.plex');

    // Both movie and episode scrobbling need nothing beyond the webhook URL, so they're ready immediately.
    $component->assertSee('Ready as soon as the URL below is pasted into Plex')
        ->assertSee('Also works from the webhook alone')
        ->assertSee('needs: Server URL, Token')
        ->assertDontSee('@if');

    $component->set('plexUrl', 'http://plex.local:32400')
        ->set('plexToken', 'secret-token')
        ->call('savePlex');

    $component->assertDontSee('needs: Server URL, Token')
        ->assertDontSee('@if');
});

test('the plex webhook secret can be regenerated', function () {
    $this->actingAs(User::factory()->create());

    $component = Livewire::test('pages::settings.integrations.plex');

    $original = $component->get('plexWebhookUrl');

    $component->call('regeneratePlexWebhookSecret');

    expect($component->get('plexWebhookUrl'))->not->toBe($original);
});

test('it shows an account picker with owner and blank-name accounts handled once url and token are configured', function () {
    $this->actingAs(User::factory()->create());

    app(IntegrationSettings::class)->setMany([
        'plex.url' => 'http://plex.local:32400',
        'plex.token' => 'secret-token',
    ]);

    Http::fake([
        '*plex.local:32400/accounts*' => Http::response([
            'MediaContainer' => ['Account' => [
                ['id' => 1, 'name' => 'vmitchell85'],
                ['id' => 2, 'name' => ''],
                ['id' => 3, 'name' => 'guest'],
            ]],
        ]),
    ]);

    $component = Livewire::test('pages::settings.integrations.plex');

    $component->assertSee('vmitchell85 (owner)')
        ->assertSee('guest (3)')
        ->assertDontSee('@if');

    expect($component->get('plexAccounts'))->toHaveCount(2);
});

test('it falls back to a free-text account id field when url or token are not configured', function () {
    $this->actingAs(User::factory()->create());

    $component = Livewire::test('pages::settings.integrations.plex');

    expect($component->get('plexAccounts'))->toBe([]);

    $component->assertSee('Account ID (optional)')
        ->assertDontSee('@if');
});

test('it falls back to the free-text field when the accounts lookup fails', function () {
    $this->actingAs(User::factory()->create());

    app(IntegrationSettings::class)->setMany([
        'plex.url' => 'http://plex.local:32400',
        'plex.token' => 'secret-token',
    ]);

    Http::fake([
        '*plex.local:32400/accounts*' => Http::response(null, 500),
    ]);

    $component = Livewire::test('pages::settings.integrations.plex');

    expect($component->get('plexAccounts'))->toBe([]);

    $component->assertSee('Account ID (optional)');
});

test('test connection reports success when there is no account configured', function () {
    $this->actingAs(User::factory()->create());

    app(IntegrationSettings::class)->setMany([
        'plex.url' => 'http://plex.local:32400',
        'plex.token' => 'secret-token',
    ]);

    Http::fake([
        '*plex.local:32400/identity*' => Http::response(['MediaContainer' => []]),
    ]);

    Livewire::test('pages::settings.integrations.plex')
        ->call('testPlex')
        ->assertDispatched('toast-show', fn ($name, $params): bool => ($params['slots']['text'] ?? null) === 'Plex connection successful.');
});

test('test connection reports that the configured account was not found', function () {
    $this->actingAs(User::factory()->create());

    app(IntegrationSettings::class)->setMany([
        'plex.url' => 'http://plex.local:32400',
        'plex.token' => 'secret-token',
        'plex.account_id' => 'nobody',
    ]);

    Http::fake([
        '*plex.local:32400/identity*' => Http::response(['MediaContainer' => []]),
        '*plex.local:32400/accounts*' => Http::response(['MediaContainer' => ['Account' => [['id' => 1, 'name' => 'vmitchell85']]]]),
    ]);

    Livewire::test('pages::settings.integrations.plex')
        ->call('testPlex')
        ->assertDispatched('toast-show', fn ($name, $params): bool => ($params['slots']['text'] ?? null) === 'Plex connected, but the configured account was not found.');
});

test('the library sync card is hidden until Plex is configured', function () {
    $this->actingAs(User::factory()->create());

    $component = Livewire::test('pages::settings.integrations.plex');

    $component->assertDontSee('Sync library now')
        ->assertDontSee('@if');
});

test('the library sync card shows the not-yet-indexed message once Plex is configured', function () {
    $this->actingAs(User::factory()->create());

    app(IntegrationSettings::class)->setMany(['plex.url' => 'http://plex.local:32400', 'plex.token' => 'secret-token']);

    Http::fake(['*plex.local:32400/accounts*' => Http::response(['MediaContainer' => ['Account' => []]])]);

    Livewire::test('pages::settings.integrations.plex')
        ->assertSee('Sync library now')
        ->assertSee('Library not indexed yet')
        ->assertDontSee('@if');
});

test('the library sync card shows item count and last sync time after a sync', function () {
    $this->actingAs(User::factory()->create());

    app(IntegrationSettings::class)->setMany([
        'plex.url' => 'http://plex.local:32400',
        'plex.token' => 'secret-token',
        'plex.library_index' => ['status' => 'success', 'items_indexed' => 42, 'finished_at' => '2026-09-23T12:00:00+00:00'],
    ]);

    PlexLibraryItem::factory()->count(3)->create();

    Http::fake(['*plex.local:32400/accounts*' => Http::response(['MediaContainer' => ['Account' => []]])]);

    Livewire::test('pages::settings.integrations.plex')
        ->assertSee('3 items indexed')
        ->assertDontSee('@if');
});

test('the library sync card shows the failure message when the last sync failed', function () {
    $this->actingAs(User::factory()->create());

    app(IntegrationSettings::class)->setMany([
        'plex.url' => 'http://plex.local:32400',
        'plex.token' => 'secret-token',
        'plex.library_index' => ['status' => 'failed', 'error' => 'connection refused', 'finished_at' => '2026-09-23T12:00:00+00:00'],
    ]);

    Http::fake(['*plex.local:32400/accounts*' => Http::response(['MediaContainer' => ['Account' => []]])]);

    Livewire::test('pages::settings.integrations.plex')
        ->assertSee('Library sync failed: connection refused')
        ->assertDontSee('@if');
});

test('clicking sync library now dispatches the sync job', function () {
    $this->actingAs(User::factory()->create());

    Bus::fake();

    app(IntegrationSettings::class)->setMany(['plex.url' => 'http://plex.local:32400', 'plex.token' => 'secret-token']);

    Http::fake(['*plex.local:32400/accounts*' => Http::response(['MediaContainer' => ['Account' => []]])]);

    Livewire::test('pages::settings.integrations.plex')
        ->call('syncLibrary')
        ->assertDispatched('toast-show', fn ($name, $params): bool => ($params['slots']['text'] ?? null) === 'Library sync started — this can take a minute for a large library.');

    Bus::assertDispatched(SyncPlexLibrary::class);
});

test('test connection reports failure when the server is unreachable', function () {
    $this->actingAs(User::factory()->create());

    app(IntegrationSettings::class)->setMany([
        'plex.url' => 'http://plex.local:32400',
        'plex.token' => 'secret-token',
    ]);

    Http::fake([
        '*plex.local:32400/identity*' => Http::response(null, 500),
    ]);

    Livewire::test('pages::settings.integrations.plex')
        ->call('testPlex')
        ->assertDispatched('toast-show', fn ($name, $params): bool => ($params['slots']['text'] ?? null) === 'Plex connection failed.');
});

test('starting sign in with plex creates a pin and dispatches the auth url', function () {
    $this->actingAs(User::factory()->create());

    Http::fake([
        'plex.tv/api/v2/pins' => Http::response(['id' => 99, 'code' => 'WXYZ']),
    ]);

    Livewire::test('pages::settings.integrations.plex')
        ->call('startPlexSignIn')
        ->assertSet('plexSignInPolling', true)
        ->assertSet('plexSignInPinId', 99)
        ->assertDispatched('plex-auth-opened');
});

test('polling sign in stores the token, account name, and discovered servers once the pin resolves', function () {
    $this->actingAs(User::factory()->create());

    Http::fake([
        'plex.tv/api/v2/pins/99' => Http::response(['id' => 99, 'authToken' => 'user-token']),
        'plex.tv/api/v2/user' => Http::response(['id' => 555, 'uuid' => 'account-uuid', 'username' => 'vmitchell85', 'email' => 'v@example.com']),
        'plex.tv/api/v2/resources*' => Http::response([
            [
                'provides' => 'server',
                'clientIdentifier' => 'the-machine-id',
                'name' => 'Home Server',
                'owned' => true,
                'presence' => true,
                'connections' => [
                    ['uri' => 'http://192.168.1.5:32400', 'local' => true, 'relay' => false, 'protocol' => 'http'],
                ],
            ],
        ]),
    ]);

    $component = Livewire::test('pages::settings.integrations.plex')
        ->set('plexSignInPinId', 99)
        ->set('plexSignInPolling', true)
        ->call('pollPlexSignIn');

    $component->assertSet('plexSignInPolling', false)
        ->assertSet('plexAccountName', 'vmitchell85')
        ->assertSee('Home Server')
        ->assertDontSee('@if');

    $settings = app(IntegrationSettings::class);

    expect($settings->get('plex.token'))->toBe('user-token')
        ->and($settings->get('plex.account_uuid'))->toBe('account-uuid')
        ->and($settings->get('plex.servers'))->toHaveCount(1);
});

test('polling sign in stops after the max attempts without a token', function () {
    $this->actingAs(User::factory()->create());

    Http::fake([
        'plex.tv/api/v2/pins/99' => Http::response(['id' => 99, 'authToken' => null]),
    ]);

    Livewire::test('pages::settings.integrations.plex')
        ->set('plexSignInPinId', 99)
        ->set('plexSignInPolling', true)
        ->set('plexSignInAttempts', 39)
        ->call('pollPlexSignIn')
        ->assertSet('plexSignInPolling', false)
        ->assertDispatched('toast-show', fn ($name, $params): bool => ($params['slots']['text'] ?? null) === 'Plex sign-in timed out. Try again.');
});

test('selecting a discovered server runs connection selection and saves the winning url', function () {
    $this->actingAs(User::factory()->create());

    app(IntegrationSettings::class)->setMany([
        'plex.token' => 'user-token',
        'plex.servers' => [[
            'machine_identifier' => 'the-machine-id',
            'name' => 'Home Server',
            'owned' => true,
            'presence' => true,
            'access_token' => 'user-token',
            'connections' => [
                ['uri' => 'http://192.168.1.5:32400', 'local' => true, 'relay' => false, 'protocol' => 'http'],
            ],
        ]],
    ]);

    Http::fake([
        '192.168.1.5:32400/identity' => Http::response(['MediaContainer' => ['machineIdentifier' => 'the-machine-id']]),
    ]);

    Livewire::test('pages::settings.integrations.plex')
        ->call('selectPlexServer', 'the-machine-id')
        ->assertSet('plexUrl', 'http://192.168.1.5:32400')
        ->assertSet('plexManualOverride', false)
        ->assertDispatched('toast-show', fn ($name, $params): bool => ($params['slots']['text'] ?? null) === 'Connected to Home Server (local).');

    $settings = app(IntegrationSettings::class);

    expect($settings->get('plex.url'))->toBe('http://192.168.1.5:32400')
        ->and($settings->get('plex.selected_machine_identifier'))->toBe('the-machine-id')
        ->and($settings->get('plex.manual_override'))->toBeFalse();
});

test('selecting a discovered server reports failure when no connection is reachable', function () {
    $this->actingAs(User::factory()->create());

    app(IntegrationSettings::class)->setMany([
        'plex.token' => 'user-token',
        'plex.servers' => [[
            'machine_identifier' => 'the-machine-id',
            'name' => 'Home Server',
            'owned' => true,
            'presence' => true,
            'access_token' => 'user-token',
            'connections' => [
                ['uri' => 'http://192.168.1.5:32400', 'local' => true, 'relay' => false, 'protocol' => 'http'],
            ],
        ]],
    ]);

    Http::fake([
        '192.168.1.5:32400/identity' => Http::response(null, 500),
    ]);

    Livewire::test('pages::settings.integrations.plex')
        ->call('selectPlexServer', 'the-machine-id')
        ->assertDispatched('toast-show', fn ($name, $params): bool => ($params['slots']['text'] ?? null) === 'Could not connect to Home Server yet — make sure it\'s online and try again.');

    expect(app(IntegrationSettings::class)->get('plex.url'))->toBeNull();
});

test('manual url override keeps the server url field editable and legacy config untouched', function () {
    $this->actingAs(User::factory()->create());

    app(IntegrationSettings::class)->setMany([
        'plex.url' => 'http://plex.local:32400',
        'plex.token' => 'legacy-token',
    ]);

    Http::fake([
        '*plex.local:32400/accounts*' => Http::response(['MediaContainer' => ['Account' => []]]),
    ]);

    $component = Livewire::test('pages::settings.integrations.plex');

    $component->assertSet('plexManualOverride', true)
        ->assertSet('plexUrl', 'http://plex.local:32400')
        ->assertDontSee('@if');

    $settings = app(IntegrationSettings::class);

    expect($settings->get('plex.url'))->toBe('http://plex.local:32400')
        ->and($settings->get('plex.token'))->toBe('legacy-token')
        ->and($settings->get('plex.selected_machine_identifier'))->toBeNull();
});

test('toggling manual override persists the flag', function () {
    $this->actingAs(User::factory()->create());

    Livewire::test('pages::settings.integrations.plex')
        ->assertSet('plexManualOverride', true)
        ->call('toggleManualOverride')
        ->assertSet('plexManualOverride', false);

    expect(app(IntegrationSettings::class)->get('plex.manual_override'))->toBeFalse();
});

test('test connection saves unsaved form values before testing', function () {
    $this->actingAs(User::factory()->create());

    Http::fake([
        '*plex.local:32400/identity*' => Http::response(['MediaContainer' => []]),
        '*plex.local:32400/accounts*' => Http::response(['MediaContainer' => ['Account' => []]]),
    ]);

    Livewire::test('pages::settings.integrations.plex')
        ->set('plexUrl', 'http://plex.local:32400')
        ->set('plexToken', 'fresh-token')
        ->call('testPlex')
        ->assertSet('plexToken', '')
        ->assertDispatched('toast-show', fn ($name, $params): bool => ($params['slots']['text'] ?? null) === 'Plex connection successful.');

    $settings = app(IntegrationSettings::class);

    expect($settings->get('plex.url'))->toBe('http://plex.local:32400')
        ->and($settings->get('plex.token'))->toBe('fresh-token');
});

test('test connection with a blank token keeps the saved token', function () {
    $this->actingAs(User::factory()->create());

    app(IntegrationSettings::class)->set('plex.token', 'original-token');

    Http::fake([
        '*plex.local:32400/identity*' => Http::response(['MediaContainer' => []]),
        '*plex.local:32400/accounts*' => Http::response(['MediaContainer' => ['Account' => []]]),
    ]);

    Livewire::test('pages::settings.integrations.plex')
        ->set('plexUrl', 'http://plex.local:32400')
        ->set('plexToken', '')
        ->call('testPlex');

    expect(app(IntegrationSettings::class)->get('plex.token'))->toBe('original-token');
});

test('test connection with an invalid url saves nothing and runs no test', function () {
    $this->actingAs(User::factory()->create());

    Livewire::test('pages::settings.integrations.plex')
        ->set('plexUrl', 'not a url')
        ->set('plexToken', 'fresh-token')
        ->call('testPlex')
        ->assertHasErrors(['plexUrl' => 'url'])
        ->assertNotDispatched('toast-show');

    expect(app(IntegrationSettings::class)->get('plex.token'))->toBeNull();

    Http::assertNothingSent();
});
