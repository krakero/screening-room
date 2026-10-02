<?php

use App\Models\User;
use App\Support\IntegrationSettings;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

beforeEach(function () {
    Http::preventStrayRequests();
});

test('signing in with plex during setup creates, links, and logs in the admin user', function () {
    $this->markNotInstalled();

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

    Livewire::test('pages::setup.account')
        ->set('plexSignInPinId', 99)
        ->set('plexSignInPolling', true)
        ->call('pollPlexSignIn')
        ->assertRedirect(route('setup.tmdb'));

    $user = User::query()->sole();

    expect($user->name)->toBe('vmitchell85')
        ->and($user->email)->toBe('v@example.com')
        ->and($user->plex_account_id)->toBe('account-uuid')
        ->and($user->plex_username)->toBe('vmitchell85')
        ->and($user->plex_linked_at)->not->toBeNull()
        ->and($user->password_set)->toBeFalse();

    $this->assertAuthenticatedAs($user);

    $settings = app(IntegrationSettings::class);

    expect($settings->get('plex.token'))->toBe('user-token')
        ->and($settings->get('plex.servers'))->toHaveCount(1);
});

test('signing in with plex during setup falls back to a synthesized email when plex has none', function () {
    $this->markNotInstalled();

    Http::fake([
        'plex.tv/api/v2/pins/99' => Http::response(['id' => 99, 'authToken' => 'user-token']),
        'plex.tv/api/v2/user' => Http::response(['id' => 555, 'uuid' => 'account-uuid', 'username' => 'vmitchell85', 'email' => '']),
        'plex.tv/api/v2/resources*' => Http::response([]),
    ]);

    Livewire::test('pages::setup.account')
        ->set('plexSignInPinId', 99)
        ->set('plexSignInPolling', true)
        ->call('pollPlexSignIn');

    expect(User::query()->sole()->email)->toBe('vmitchell85@plex.local');
});

test('setup still redirects to tmdb once a user already exists', function () {
    $this->markNotInstalled();
    User::factory()->create();

    Livewire::test('pages::setup.account')
        ->assertRedirect(route('setup.tmdb'));
});

test('the setup account step shows plex sign-in as primary and email under other options', function () {
    $this->markNotInstalled();

    $response = $this->get(route('setup.account'));

    $response->assertOk()
        ->assertSee('Sign in with Plex')
        ->assertSee('Other options')
        ->assertSee('Create an account with email instead')
        ->assertSeeInOrder(['Sign in with Plex', 'Other options', 'Create account & continue']);
});

test('email and password setup is unaffected by the plex sign-in addition', function () {
    $this->markNotInstalled();

    Livewire::test('pages::setup.account')
        ->set('name', 'Vince')
        ->set('email', 'vince@example.com')
        ->set('password', 'password12345')
        ->set('password_confirmation', 'password12345')
        ->call('createAccount')
        ->assertRedirect(route('setup.tmdb'));

    $user = User::query()->sole();

    expect($user->email)->toBe('vince@example.com')
        ->and($user->password_set)->toBeTrue()
        ->and($user->plex_account_id)->toBeNull();

    $this->assertAuthenticatedAs($user);
});

test('the account step only polls plex while a sign-in is in progress', function () {
    $this->markNotInstalled();

    Livewire::test('pages::setup.account')
        ->assertDontSeeHtml('wire:poll')
        ->set('plexSignInPinId', 99)
        ->set('plexSignInPolling', true)
        ->assertSeeHtml('wire:poll.2s="pollPlexSignIn"');
});
