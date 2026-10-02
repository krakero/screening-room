<?php

use App\Models\User;

test('the login page renders the new logo mark, not the old Laravel starter mark', function () {
    $response = $this->get(route('login'));

    $response->assertOk();
    $response->assertSee('viewBox="0 0 32 32"', false);
    $response->assertSee('M12.5 23c2.4-2 4.8-2 7 0', false);
    $response->assertDontSee('viewBox="0 0 40 42"', false);
    $response->assertDontSee('M17.2 5.633', false);
});

test('the dashboard header renders the new logo mark, not the old Laravel starter mark', function () {
    $this->actingAs(User::factory()->create());

    $response = $this->get(route('dashboard'));

    $response->assertOk();
    $response->assertSee('viewBox="0 0 32 32"', false);
    $response->assertSee('M12.5 23c2.4-2 4.8-2 7 0', false);
    $response->assertDontSee('viewBox="0 0 40 42"', false);
    $response->assertDontSee('M17.2 5.633', false);
});
