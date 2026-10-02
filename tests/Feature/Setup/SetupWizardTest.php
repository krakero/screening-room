<?php

use App\Models\IntegrationSetting;
use App\Models\User;
use App\Support\IntegrationSettings;
use App\Support\SetupProgress;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

// --- Gating ---------------------------------------------------------------

test('no user redirects any route to the account step', function () {
    $this->markNotInstalled();

    $response = $this->get('/dashboard');

    $response->assertRedirect(route('setup.account'));
});

test('a user without tmdb configured redirects to the tmdb step', function () {
    $this->markNotInstalled();
    config(['services.tmdb.token' => null]);
    $this->actingAs(User::factory()->create());

    $response = $this->get('/dashboard');

    $response->assertRedirect(route('setup.tmdb'));
});

test('mid-wizard resume redirects to the furthest reached optional step', function () {
    $this->markNotInstalled();
    config(['services.tmdb.token' => 'test-token']);
    $this->actingAs(User::factory()->create());
    app(SetupProgress::class)->markReached('plex');

    $response = $this->get('/dashboard');

    $response->assertRedirect(route('setup.plex'));
});

test('an installed app is not gated', function () {
    $this->actingAs(User::factory()->create());

    $response = $this->get('/dashboard');

    $response->assertOk();
});

test('an installed app redirects setup routes to the dashboard', function () {
    $this->actingAs(User::factory()->create());

    $response = $this->get(route('setup.tmdb'));

    $response->assertRedirect(route('dashboard'));
});

test('webhook routes are not gated even when not installed', function () {
    $this->markNotInstalled();

    $response = $this->postJson('/webhooks/arr/some-secret', []);

    $response->assertNotFound();
});

test('the health check route is not gated even when not installed', function () {
    $this->markNotInstalled();

    $response = $this->get('/up');

    $response->assertOk();
});

// --- Existing-install migration --------------------------------------------

test('the existing-install migration marks an install with a user already present', function () {
    $this->markNotInstalled();
    User::factory()->create();

    (require database_path('migrations/2026_09_23_150000_mark_existing_installs_as_set_up.php'))->up();

    expect(app(IntegrationSettings::class)->get('setup.finished'))->toBeTrue();
});

test('the existing-install migration leaves a fresh install alone', function () {
    $this->markNotInstalled();

    (require database_path('migrations/2026_09_23_150000_mark_existing_installs_as_set_up.php'))->up();

    expect(app(IntegrationSettings::class)->get('setup.finished'))->not->toBeTrue();
});

// --- Account step -----------------------------------------------------------

test('the account step creates the owner account and logs them in', function () {
    Livewire::test('pages::setup.account')
        ->set('name', 'Ada Lovelace')
        ->set('email', 'ada@example.com')
        ->set('password', 'password123')
        ->set('password_confirmation', 'password123')
        ->call('createAccount')
        ->assertRedirect(route('setup.tmdb'));

    $user = User::sole();

    expect($user->name)->toBe('Ada Lovelace')
        ->and($user->email)->toBe('ada@example.com')
        ->and($user->email_verified_at)->not->toBeNull();

    $this->assertAuthenticatedAs($user);
});

test('the account step is locked once a user exists', function () {
    $this->actingAs(User::factory()->create());

    Livewire::test('pages::setup.account')->assertRedirect(route('setup.tmdb'));
});

test('visiting the account step directly redirects to tmdb once a user exists', function () {
    $this->markNotInstalled();
    User::factory()->create();

    $response = $this->get(route('setup.account'));

    $response->assertRedirect(route('setup.tmdb'));
});

// --- TMDB step ---------------------------------------------------------------

test('the tmdb step saves the token encrypted and requires a working test connection', function () {
    Http::preventStrayRequests();
    Http::fake([
        '*/authentication*' => Http::response(['success' => true]),
    ]);

    $this->actingAs(User::factory()->create());

    Livewire::test('pages::setup.tmdb')
        ->set('token', 'a-real-token')
        ->call('saveAndContinue')
        ->assertRedirect(route('setup.preferences'));

    expect(app(IntegrationSettings::class)->get('tmdb.token'))->toBe('a-real-token');

    $row = IntegrationSetting::where('key', 'tmdb.token')->sole();
    expect($row->getRawOriginal('value'))->not->toContain('a-real-token');
});

test('the tmdb step does not advance when the token fails to connect', function () {
    Http::preventStrayRequests();
    Http::fake([
        '*/authentication*' => Http::response(['status_message' => 'Invalid API key'], 401),
    ]);

    $this->actingAs(User::factory()->create());

    Livewire::test('pages::setup.tmdb')
        ->set('token', 'a-bad-token')
        ->call('saveAndContinue')
        ->assertHasErrors('token')
        ->assertNoRedirect();

    expect(app(IntegrationSettings::class)->get('tmdb.token'))->toBeNull();
});

// --- Preferences step ----------------------------------------------------------

test('the preferences step saves the region and continues', function () {
    $this->actingAs(User::factory()->create());

    Livewire::test('pages::setup.preferences')
        ->set('region', 'GB')
        ->call('savePreferences')
        ->assertRedirect(route('setup.import'));

    expect(app(IntegrationSettings::class)->get('tmdb.region'))->toBe('GB');
});

test('the preferences step saves the timezone', function () {
    $user = User::factory()->create(['timezone' => null]);
    $this->actingAs($user);

    Livewire::test('pages::setup.preferences')
        ->set('timezone', 'America/Los_Angeles')
        ->call('savePreferences')
        ->assertRedirect(route('setup.import'));

    expect($user->fresh()->timezone)->toBe('America/Los_Angeles');
});

test('the preferences step shows a step indicator and a back link to tmdb', function () {
    $this->markNotInstalled();
    $this->actingAs(User::factory()->create());

    $response = $this->get(route('setup.preferences'));

    $response->assertOk();
    $response->assertSee('Step 3 of 7');
    $response->assertSee(route('setup.tmdb'), false);
});

// --- Done step -------------------------------------------------------------

test('the done step marks setup finished and links into the app', function () {
    $this->markNotInstalled();
    $this->actingAs(User::factory()->create());

    $response = $this->get(route('setup.done'));

    $response->assertOk();
    $response->assertDontSee('@if');

    expect(app(IntegrationSettings::class)->get('setup.finished'))->toBeTrue();
});

// --- No more app-shell banner ----------------------------------------------

test('the dashboard no longer shows a resume-setup banner', function () {
    $this->actingAs(User::factory()->create());

    $response = $this->get(route('dashboard'));

    $response->assertOk();
    $response->assertDontSee('Finish setting up Screening Room');
});

test('the account step shows the app name', function () {
    $this->markNotInstalled();

    $response = $this->get(route('setup.account'));

    $response->assertOk();
    $response->assertSee('Screening Room');
});

// --- Every wizard step renders as a dedicated page, no app chrome ----------

foreach (['account' => false, 'tmdb' => true, 'preferences' => true, 'import' => true, 'plex' => true, 'requests' => true, 'notifications' => true, 'done' => true] as $step => $requiresAuth) {
    test("the {$step} step renders without app chrome", function () use ($step, $requiresAuth) {
        $this->markNotInstalled();

        if ($requiresAuth) {
            $this->actingAs(User::factory()->create());
        }

        $response = $this->get(route("setup.{$step}"));

        $response->assertOk();
        $response->assertDontSee('@if');
        $response->assertDontSee('aria-label="Primary"', false);
    });
}
