<?php

use App\Events\TitleBecameAvailable;
use App\Models\Episode;
use App\Models\Follow;
use App\Models\Season;
use App\Models\Title;
use App\Models\User;
use App\Notifications\TitleAvailable;
use App\Support\IntegrationSettings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::preventStrayRequests();
});

test('the pushover channel sends the notification message via the pushover client', function () {
    $user = User::factory()->create();

    app(IntegrationSettings::class)->setMany([
        'pushover.user_key' => 'user-key',
        'pushover.app_token' => 'app-token',
    ]);

    Http::fake([
        'https://api.pushover.net/1/messages.json' => Http::response(['status' => 1]),
    ]);

    $title = Title::factory()->movie()->create(['name' => 'Fight Club']);

    $user->notify(new TitleAvailable($title));

    Http::assertSent(fn ($request) => $request['title'] === __('Now available') && $request['message'] === 'Fight Club');
});

test('a title becoming available notifies the user when pushover is configured', function () {
    $user = User::factory()->create();

    app(IntegrationSettings::class)->setMany([
        'pushover.user_key' => 'user-key',
        'pushover.app_token' => 'app-token',
    ]);

    Http::fake([
        'https://api.pushover.net/1/messages.json' => Http::response(['status' => 1]),
    ]);

    $title = Title::factory()->movie()->create(['name' => 'The Matrix']);

    event(new TitleBecameAvailable($title));

    Http::assertSent(fn ($request) => $request['message'] === 'The Matrix');
});

test('a title becoming available sends nothing when pushover is not configured', function () {
    User::factory()->create();

    Http::fake();

    $title = Title::factory()->movie()->create();

    event(new TitleBecameAvailable($title));

    Http::assertNothingSent();
});

test('the daily digest notifies about episodes airing today when pushover is configured', function () {
    $user = User::factory()->create(['timezone' => 'UTC']);

    app(IntegrationSettings::class)->setMany([
        'pushover.user_key' => 'user-key',
        'pushover.app_token' => 'app-token',
    ]);

    $show = Title::factory()->show()->create(['name' => 'Severance']);
    $season = Season::factory()->for($show)->create(['season_number' => 1]);
    Follow::factory()->for($show)->create();

    Episode::factory()->for($season)->create([
        'title_id' => $show->id,
        'episode_number' => 1,
        'name' => 'Hello, Ms. Cobel',
        'air_date' => Carbon::today(),
    ]);

    Http::fake([
        'https://api.pushover.net/1/messages.json' => Http::response(['status' => 1]),
    ]);

    $this->artisan('notifications:episodes-airing-today')->assertExitCode(0);

    Http::assertSent(fn ($request) => str_contains($request['message'], 'Hello, Ms. Cobel'));
});

test('the daily digest sends nothing when pushover is not configured', function () {
    User::factory()->create(['timezone' => 'UTC']);

    $show = Title::factory()->show()->create();
    $season = Season::factory()->for($show)->create(['season_number' => 1]);
    Follow::factory()->for($show)->create();

    Episode::factory()->for($season)->create([
        'title_id' => $show->id,
        'episode_number' => 1,
        'air_date' => Carbon::today(),
    ]);

    Http::fake();

    $this->artisan('notifications:episodes-airing-today')->assertExitCode(0);

    Http::assertNothingSent();
});

test('the daily digest sends nothing when nothing is airing today', function () {
    app(IntegrationSettings::class)->setMany([
        'pushover.user_key' => 'user-key',
        'pushover.app_token' => 'app-token',
    ]);

    Http::fake();

    $this->artisan('notifications:episodes-airing-today')->assertExitCode(0);

    Http::assertNothingSent();
});
