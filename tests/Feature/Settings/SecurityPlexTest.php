<?php

use App\Models\User;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

beforeEach(function () {
    Http::preventStrayRequests();
});

function actingAsConfirmed(?User $user = null): User
{
    $user ??= User::factory()->create();

    test()->actingAs($user)->withSession(['auth.password_confirmed_at' => time()]);

    return $user;
}

test('linking a plex account via sign-in stores the uuid and username', function () {
    actingAsConfirmed();

    Http::fake([
        'plex.tv/api/v2/pins/99' => Http::response(['id' => 99, 'authToken' => 'user-token']),
        'plex.tv/api/v2/user' => Http::response(['id' => 1, 'uuid' => 'account-uuid', 'username' => 'vmitchell85', 'email' => 'v@example.com']),
    ]);

    Livewire::test('pages::settings.security')
        ->set('plexSignInPinId', 99)
        ->set('plexSignInPolling', true)
        ->call('pollPlexLink')
        ->assertSet('plexLinked', true)
        ->assertSee('Connected as vmitchell85')
        ->assertDontSee('@if');

    $user = auth()->user()->fresh();

    expect($user->plex_account_id)->toBe('account-uuid')
        ->and($user->plex_username)->toBe('vmitchell85')
        ->and($user->plex_linked_at)->not->toBeNull();
});

test('linking rejects a plex account already linked to a different user', function () {
    User::factory()->withLinkedPlex('account-uuid')->create();
    actingAsConfirmed();

    Http::fake([
        'plex.tv/api/v2/pins/99' => Http::response(['id' => 99, 'authToken' => 'user-token']),
        'plex.tv/api/v2/user' => Http::response(['id' => 1, 'uuid' => 'account-uuid', 'username' => 'someone-else', 'email' => 'x@example.com']),
    ]);

    Livewire::test('pages::settings.security')
        ->set('plexSignInPinId', 99)
        ->set('plexSignInPolling', true)
        ->call('pollPlexLink')
        ->assertSet('plexLinked', false)
        ->assertDispatched('toast-show', fn ($name, $params): bool => ($params['slots']['text'] ?? null) === 'That Plex account is already linked to a different user.');
});

test('unlinking clears the plex fields when the user has a usable password', function () {
    $user = User::factory()->withLinkedPlex('account-uuid')->create();
    actingAsConfirmed($user);

    Livewire::test('pages::settings.security')
        ->call('unlinkPlex')
        ->assertSet('plexLinked', false);

    $user->refresh();

    expect($user->plex_account_id)->toBeNull()
        ->and($user->plex_username)->toBeNull()
        ->and($user->plex_linked_at)->toBeNull();
});

test('unlinking is blocked when the user has no usable password', function () {
    $user = User::factory()->withLinkedPlex('account-uuid')->withoutUsablePassword()->create();
    actingAsConfirmed($user);

    Livewire::test('pages::settings.security')
        ->call('unlinkPlex')
        ->assertDispatched('toast-show', fn ($name, $params): bool => str_contains($params['slots']['text'] ?? '', 'Set a password first'));

    expect($user->fresh()->plex_account_id)->toBe('account-uuid');
});

test('a user without a usable password sees the set-password prompt instead of the normal password form', function () {
    actingAsConfirmed(User::factory()->withoutUsablePassword()->create());

    Livewire::test('pages::settings.security')
        ->assertSee('doesn\'t have a password yet')
        ->assertSee('Set password')
        ->assertDontSee('Current password')
        ->assertDontSee('@if');
});

test('setting an initial password marks the account as having a usable password', function () {
    $user = User::factory()->withoutUsablePassword()->create();
    actingAsConfirmed($user);

    Livewire::test('pages::settings.security')
        ->set('newPassword', 'a-strong-password-123')
        ->set('newPassword_confirmation', 'a-strong-password-123')
        ->call('setInitialPassword')
        ->assertSet('passwordSet', true)
        ->assertHasNoErrors();

    expect($user->fresh()->password_set)->toBeTrue();
});
