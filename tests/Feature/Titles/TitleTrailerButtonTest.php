<?php

use App\Models\Title;
use App\Models\User;
use Livewire\Livewire;

test('the title page has no Watch trailer button when there is no trailer', function () {
    $this->actingAs(User::factory()->create());

    $title = Title::factory()->movie()->create(['trailer_site' => null, 'trailer_key' => null]);

    $response = $this->get(route('titles.show', $title));

    $response->assertOk();
    $response->assertDontSee('@if');
    $response->assertDontSee('Watch trailer');
});

test('the title page shows a Watch trailer button when a trailer is set', function () {
    $this->actingAs(User::factory()->create());

    $title = Title::factory()->movie()->create(['trailer_site' => 'YouTube', 'trailer_key' => 'abc123']);

    $response = $this->get(route('titles.show', $title));

    $response->assertOk();
    $response->assertDontSee('@if');
    $response->assertSee('Watch trailer');
    $response->assertSee('Open on YouTube');
});

test('the trailer modal only renders the embed while open', function () {
    $this->actingAs(User::factory()->create());

    $title = Title::factory()->movie()->create(['trailer_site' => 'YouTube', 'trailer_key' => 'abc123']);

    Livewire::test('pages::titles.show', ['title' => $title])
        ->assertDontSee('youtube-nocookie.com/embed/abc123')
        ->call('openTrailer')
        ->assertSee('youtube-nocookie.com/embed/abc123')
        ->call('closeTrailer')
        ->assertDontSee('youtube-nocookie.com/embed/abc123');
});

test('a Vimeo trailer links to vimeo.com and embeds the player.vimeo.com URL', function () {
    $this->actingAs(User::factory()->create());

    $title = Title::factory()->movie()->create(['trailer_site' => 'Vimeo', 'trailer_key' => '76979871']);

    Livewire::test('pages::titles.show', ['title' => $title])
        ->assertSee('Open on Vimeo')
        ->call('openTrailer')
        ->assertSee('player.vimeo.com/video/76979871');
});
