<?php

use App\Models\User;
use App\Services\Stats\StatsCacheVersion;
use Livewire\Livewire;

test('the appearance settings page renders the timezone select', function () {
    $this->actingAs(User::factory()->create());

    $response = $this->get(route('appearance.edit'));

    $response->assertOk();
    $response->assertSee('Timezone');
    $response->assertDontSee('@if');
});

test('mounting defaults to the app display timezone when the user has none set', function () {
    $user = User::factory()->create(['timezone' => null]);
    $this->actingAs($user);

    Livewire::test('pages::settings.appearance')
        ->assertSet('timezone', config('app.display_timezone'));
});

test('a timezone can be saved and bumps the stats cache version', function () {
    $user = User::factory()->create(['timezone' => null]);
    $this->actingAs($user);

    $versionBefore = app(StatsCacheVersion::class)->current();

    Livewire::test('pages::settings.appearance')
        ->set('timezone', 'America/Los_Angeles')
        ->call('saveTimezone');

    expect($user->fresh()->timezone)->toBe('America/Los_Angeles')
        ->and(app(StatsCacheVersion::class)->current())->toBeGreaterThan($versionBefore);
});

test('an invalid timezone is rejected', function () {
    $user = User::factory()->create(['timezone' => 'America/New_York']);
    $this->actingAs($user);

    Livewire::test('pages::settings.appearance')
        ->set('timezone', 'Not/AZone')
        ->call('saveTimezone')
        ->assertHasErrors(['timezone']);

    expect($user->fresh()->timezone)->toBe('America/New_York');
});
