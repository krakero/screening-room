<?php

use App\Models\User;

test('guests are redirected to the login page', function () {
    $response = $this->get(route('styleguide'));

    $response->assertRedirect(route('login'));
});

test('authenticated users can view the styleguide with every media component', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $response = $this->get(route('styleguide'));

    $response->assertOk();
    $response->assertDontSee('@if');

    // The unified badge component: every tone × variant × size, plus real status badges.
    $response->assertSee(__('Badges'));
    $response->assertSee('Neutral sm');
    $response->assertSee('Plex md');
    $response->assertSee(['Available', 'Requested', 'Pending', 'Downloading', 'Watched', 'Abandoned', 'Paused']);

    // Poster card, including the gradient-initials fallback for a missing image.
    $response->assertSee('Fight Club');
    $response->assertSee('Untitled');

    // Poster cards share the episode card's hover/focus ring overlay (x-media.hover-ring).
    $response->assertSee('class="media-ring"', false);

    // Poster row / grid, hero, episode row, stats, chips, and empty state.
    $response->assertSee(__('Continue watching'));
    $response->assertSee(__('Poster grid'));
    $response->assertSee('Good News Is Bad News');
    $response->assertSee('Special');
    $response->assertSee('Movies watched');
    $response->assertSee('Drama');
    $response->assertSee(__('Nothing here yet'));

    // Light / dark preview renders both scopes.
    $response->assertSee(__('Light'));
    $response->assertSee(__('Dark'));
});
