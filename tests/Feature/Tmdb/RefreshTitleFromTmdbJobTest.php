<?php

use App\Actions\Tmdb\ImportTitle as ImportTitleAction;
use App\Enums\TitleType;
use App\Jobs\RefreshTitleFromTmdb;
use App\Models\Title;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Bus;

test('it implements should queue and should be unique', function () {
    $job = new RefreshTitleFromTmdb(42);

    expect($job)->toBeInstanceOf(ShouldQueue::class)
        ->and($job)->toBeInstanceOf(ShouldBeUnique::class)
        ->and($job->uniqueId())->toBe('42')
        ->and($job->backoff)->toBe([10, 60, 300]);
});

test('it dispatches with the given title id', function () {
    Bus::fake();

    RefreshTitleFromTmdb::dispatch(42);

    Bus::assertDispatched(RefreshTitleFromTmdb::class, fn (RefreshTitleFromTmdb $job): bool => $job->titleId === 42);
});

test('handle re-imports the title by its type and tmdb id', function () {
    $title = Title::factory()->create(['type' => TitleType::Show, 'tmdb_id' => 1396]);

    $action = Mockery::mock(ImportTitleAction::class);
    $action->shouldReceive('handle')->once()->with(TitleType::Show, 1396)->andReturn($title);

    (new RefreshTitleFromTmdb($title->id))->handle($action);
});
