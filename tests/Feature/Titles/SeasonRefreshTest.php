<?php

use App\Jobs\ImportSeasonEpisodes;
use App\Models\Season;
use App\Models\Title;
use App\Models\User;
use Illuminate\Support\Facades\Bus;
use Livewire\Livewire;

test('the season header has a Refresh season button', function () {
    $this->actingAs(User::factory()->create());

    $title = Title::factory()->show()->create();
    $season = Season::factory()->for($title)->create(['season_number' => 1]);

    $response = $this->get(route('titles.seasons.show', [$title, 1]));

    $response->assertOk();
    $response->assertDontSee('@if');
    $response->assertSee(__('Refresh season'));
});

test('refreshSeason dispatches a forced episode re-import for this season only', function () {
    Bus::fake();

    $this->actingAs(User::factory()->create());

    $title = Title::factory()->show()->create();
    Season::factory()->for($title)->create(['season_number' => 1]);

    Livewire::test('pages::titles.seasons.show', ['title' => $title, 'seasonNumber' => 1])
        ->call('refreshSeason');

    Bus::assertDispatched(
        ImportSeasonEpisodes::class,
        fn (ImportSeasonEpisodes $job): bool => $job->titleId === $title->id && $job->seasonNumber === 1,
    );
});
