<?php

use App\Jobs\RefreshTitleTrailer;
use App\Models\Title;
use App\Models\User;
use Illuminate\Support\Facades\Queue;

test('it dispatches a trailer refresh when the title has never been checked', function () {
    Queue::fake();
    $this->actingAs(User::factory()->create());

    $title = Title::factory()->movie()->create(['trailer_key' => null, 'trailer_checked_at' => null]);

    $this->get(route('titles.show', $title))->assertOk()->assertDontSee('@if');

    Queue::assertPushed(RefreshTitleTrailer::class, fn (RefreshTitleTrailer $job) => $job->title->is($title));
});

test('it dispatches a trailer refresh when the last check was more than 30 days ago', function () {
    Queue::fake();
    $this->actingAs(User::factory()->create());

    $title = Title::factory()->movie()->create([
        'trailer_key' => null,
        'trailer_checked_at' => now()->subDays(31),
    ]);

    $this->get(route('titles.show', $title))->assertOk();

    Queue::assertPushed(RefreshTitleTrailer::class);
});

test('it does not dispatch a trailer refresh when the title already has a trailer', function () {
    Queue::fake();
    $this->actingAs(User::factory()->create());

    $title = Title::factory()->movie()->create([
        'trailer_site' => 'YouTube',
        'trailer_key' => 'abc123',
        'trailer_checked_at' => null,
    ]);

    $this->get(route('titles.show', $title))->assertOk();

    Queue::assertNotPushed(RefreshTitleTrailer::class);
});

test('it does not dispatch a trailer refresh when the check is recent', function () {
    Queue::fake();
    $this->actingAs(User::factory()->create());

    $title = Title::factory()->movie()->create([
        'trailer_key' => null,
        'trailer_checked_at' => now()->subDays(5),
    ]);

    $this->get(route('titles.show', $title))->assertOk();

    Queue::assertNotPushed(RefreshTitleTrailer::class);
});
