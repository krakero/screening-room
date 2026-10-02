<?php

use App\Jobs\RefreshTitleFromTmdb;
use App\Models\Title;
use App\Models\User;
use Illuminate\Support\Facades\Bus;
use Livewire\Livewire;

test('the overflow menu has a Refresh from TMDB row', function () {
    $this->actingAs(User::factory()->create());

    $title = Title::factory()->movie()->create();

    $response = $this->get(route('titles.show', $title));

    $response->assertOk();
    $response->assertDontSee('@if');
    $response->assertSee(__('Refresh from TMDB'));
});

test('refreshFromTmdb dispatches a forced refresh for this title', function () {
    Bus::fake();

    $this->actingAs(User::factory()->create());

    $title = Title::factory()->movie()->create();

    Livewire::test('pages::titles.show', ['title' => $title])
        ->call('refreshFromTmdb');

    Bus::assertDispatched(RefreshTitleFromTmdb::class, fn (RefreshTitleFromTmdb $job): bool => $job->titleId === $title->id);
});
