<?php

use App\Models\Title;
use App\Models\User;

test('the title page shows a single year, not a repeated range, when a show starts and ends the same year', function () {
    $this->actingAs(User::factory()->create());

    $title = Title::factory()->show()->create([
        'release_date' => '2026-01-10',
        'last_air_date' => '2026-11-20',
        'status' => 'Ended',
        'in_production' => false,
    ]);

    $response = $this->get(route('titles.show', $title));

    $response->assertOk();
    $response->assertDontSee('@if', false);
    $response->assertSee('2026');
    $response->assertDontSee('2026–2026');
});

test('the title page shows a closed year range for an ended show', function () {
    $this->actingAs(User::factory()->create());

    $title = Title::factory()->show()->create([
        'release_date' => '2019-01-10',
        'last_air_date' => '2023-11-20',
        'status' => 'Ended',
        'in_production' => false,
    ]);

    $response = $this->get(route('titles.show', $title));

    $response->assertOk();
    $response->assertSee('2019–2023');
});

test('the title page shows an open year range for a returning show', function () {
    $this->actingAs(User::factory()->create());

    $title = Title::factory()->show()->create([
        'release_date' => '2019-01-10',
        'last_air_date' => '2023-11-20',
        'status' => 'Returning Series',
        'in_production' => true,
    ]);

    $response = $this->get(route('titles.show', $title));

    $response->assertOk();
    $response->assertSee('2019+');
    $response->assertDontSee('2019–2023');
});
