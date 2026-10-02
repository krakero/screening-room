<?php

use App\Models\Title;
use App\Models\User;

test('the title page renders a status chip for each recognized show status', function (string $tmdbStatus, string $label) {
    $this->actingAs(User::factory()->create());

    $title = Title::factory()->show()->create(['status' => $tmdbStatus]);

    $response = $this->get(route('titles.show', $title));

    $response->assertOk();
    $response->assertDontSee('@if', false);
    $response->assertSee($label);
})->with([
    ['Returning Series', 'Ongoing'],
    ['In Production', 'Upcoming'],
    ['Planned', 'Upcoming'],
    ['Pilot', 'Upcoming'],
    ['Ended', 'Ended'],
    ['Canceled', 'Canceled'],
]);

test('the title page renders no status chip for an unrecognized show status', function () {
    $this->actingAs(User::factory()->create());

    $title = Title::factory()->show()->create(['status' => 'Something Unexpected']);

    $response = $this->get(route('titles.show', $title));

    $response->assertOk();
    $response->assertDontSee('Ongoing')
        ->assertDontSee('Upcoming')
        ->assertDontSee('Ended')
        ->assertDontSee('Canceled');
});

test('movie title pages never render a show status chip', function () {
    $this->actingAs(User::factory()->create());

    $title = Title::factory()->movie()->create();

    $response = $this->get(route('titles.show', $title));

    $response->assertOk();
    $response->assertDontSee('Ongoing')
        ->assertDontSee('Upcoming')
        ->assertDontSee('Ended')
        ->assertDontSee('Canceled');
});
