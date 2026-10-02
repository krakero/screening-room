<?php

use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Fortify\Features;
use Livewire\Livewire;

beforeEach(function () {
    Http::preventStrayRequests();
});

test('the login page does not show the plex button when no user has linked plex', function () {
    User::factory()->create();

    $response = $this->get(route('login'));

    $response->assertOk()->assertDontSee('Sign in with Plex');
});

test('the login page shows the plex button once a user has linked plex', function () {
    User::factory()->withLinkedPlex()->create();

    $response = $this->get(route('login'));

    $response->assertOk()->assertSee('Sign in with Plex');
});

test('starting plex sign in on the login screen creates a pin and dispatches the auth url', function () {
    User::factory()->withLinkedPlex()->create();

    Http::fake([
        'plex.tv/api/v2/pins' => Http::response(['id' => 99, 'code' => 'WXYZ']),
    ]);

    Livewire::test('plex-login-button')
        ->call('startSignIn')
        ->assertSet('polling', true)
        ->assertDispatched('plex-auth-opened');
});

test('polling logs in the matching linked user, respecting remember me', function () {
    $user = User::factory()->withLinkedPlex('account-uuid')->create();

    Http::fake([
        'plex.tv/api/v2/pins/99' => Http::response(['id' => 99, 'authToken' => 'user-token']),
        'plex.tv/api/v2/user' => Http::response(['id' => 1, 'uuid' => 'account-uuid', 'username' => 'vmitchell85', 'email' => 'v@example.com']),
    ]);

    Livewire::test('plex-login-button')
        ->set('pinId', 99)
        ->set('polling', true)
        ->set('remember', true)
        ->call('poll')
        ->assertRedirect(route('dashboard', absolute: false));

    $this->assertAuthenticatedAs($user);
    expect($user->fresh()->getRememberToken())->not->toBeNull();
});

test('an unlinked plex account is rejected on the login screen and no user is created', function () {
    User::factory()->withLinkedPlex('someone-elses-uuid')->create();

    Http::fake([
        'plex.tv/api/v2/pins/99' => Http::response(['id' => 99, 'authToken' => 'user-token']),
        'plex.tv/api/v2/user' => Http::response(['id' => 2, 'uuid' => 'a-stranger-uuid', 'username' => 'stranger', 'email' => 's@example.com']),
    ]);

    $countBefore = User::query()->count();

    Livewire::test('plex-login-button')
        ->set('pinId', 99)
        ->set('polling', true)
        ->call('poll')
        ->assertHasErrors('plex');

    $this->assertGuest();
    expect(User::query()->count())->toBe($countBefore);
});

test('a linked user with two-factor enabled still gets the 2fa challenge after plex sign-in', function () {
    $this->skipUnlessFortifyHas(Features::twoFactorAuthentication());

    Features::twoFactorAuthentication(['confirm' => true, 'confirmPassword' => true]);

    $user = User::factory()->withTwoFactor()->withLinkedPlex('account-uuid')->create();

    Http::fake([
        'plex.tv/api/v2/pins/99' => Http::response(['id' => 99, 'authToken' => 'user-token']),
        'plex.tv/api/v2/user' => Http::response(['id' => 1, 'uuid' => 'account-uuid', 'username' => 'vmitchell85', 'email' => 'v@example.com']),
    ]);

    Livewire::test('plex-login-button')
        ->set('pinId', 99)
        ->set('polling', true)
        ->call('poll')
        ->assertRedirect(route('two-factor.login'));

    $this->assertGuest();
    expect(session('login.id'))->toBe($user->id);
});

test('plex login resolution is throttled after five unlinked attempts', function () {
    User::factory()->withLinkedPlex('someone-elses-uuid')->create();

    Http::fake([
        'plex.tv/api/v2/pins/99' => Http::response(['id' => 99, 'authToken' => 'user-token']),
        'plex.tv/api/v2/user' => Http::response(['id' => 2, 'uuid' => 'a-stranger-uuid', 'username' => 'stranger', 'email' => 's@example.com']),
    ]);

    for ($i = 0; $i < 5; $i++) {
        Livewire::test('plex-login-button')
            ->set('pinId', 99)
            ->set('polling', true)
            ->call('poll');
    }

    Livewire::test('plex-login-button')
        ->set('pinId', 99)
        ->set('polling', true)
        ->call('poll')
        ->assertSee('Too many attempts. Please try again later.');
});

afterEach(function () {
    RateLimiter::clear('plex-login|127.0.0.1');
});
