<?php

use App\Models\User;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::preventStrayRequests();
});

test('when a linked plex user exists, plex is primary and the email form is collapsed under other options', function () {
    User::factory()->withLinkedPlex()->create();

    $response = $this->get(route('login'));

    $response->assertOk()
        ->assertSee('Sign in with Plex')
        ->assertSee('Other options')
        ->assertSeeInOrder(['Sign in with Plex', 'Other options', 'Log in'])
        ->assertDontSee('<details class="group" open>', false);
});

test('without a linked plex user, the login page shows the plain email form with no disclosure', function () {
    User::factory()->create();

    $response = $this->get(route('login'));

    $response->assertOk()
        ->assertDontSee('Sign in with Plex')
        ->assertDontSee('Other options');
});

test('a failed email login expands the other options disclosure with the error', function () {
    User::factory()->withLinkedPlex()->create();
    $user = User::factory()->create(['email' => 'wrong@example.com']);

    $response = $this->from(route('login'))->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'not-the-right-password',
    ]);

    $response->assertSessionHasErrors('email');

    $follow = $this->get(route('login'));

    $follow->assertOk()
        ->assertSee('<details class="group" open>', false);
});

test('?email=1 expands the other options disclosure', function () {
    User::factory()->withLinkedPlex()->create();

    $response = $this->get(route('login', ['email' => 1]));

    $response->assertOk()->assertSee('<details class="group" open>', false);
});

test('email login still works when the disclosure is present', function () {
    User::factory()->withLinkedPlex()->create();
    $user = User::factory()->create(['email' => 'vince@example.com']);

    $response = $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $response->assertRedirect(route('dashboard', absolute: false));
    $this->assertAuthenticatedAs($user);
});
