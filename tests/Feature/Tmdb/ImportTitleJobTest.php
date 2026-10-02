<?php

use App\Actions\Tmdb\ImportTitle as ImportTitleAction;
use App\Enums\TitleType;
use App\Jobs\ImportTitle;
use App\Models\Title;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Bus;

test('it implements should queue and should be unique', function () {
    $job = new ImportTitle(TitleType::Movie, 603);

    expect($job)->toBeInstanceOf(ShouldQueue::class)
        ->and($job)->toBeInstanceOf(ShouldBeUnique::class)
        ->and($job->uniqueId())->toBe('movie:603')
        ->and($job->backoff)->toBe([10, 60, 300]);
});

test('it dispatches with the given type and tmdb id', function () {
    Bus::fake();

    ImportTitle::dispatch(TitleType::Show, 1396);

    Bus::assertDispatched(
        ImportTitle::class,
        fn (ImportTitle $job): bool => $job->type === TitleType::Show && $job->tmdbId === 1396,
    );
});

test('handle delegates to the import title action', function () {
    $title = Title::factory()->make();

    $action = Mockery::mock(ImportTitleAction::class);
    $action->shouldReceive('handle')->once()->with(TitleType::Movie, 603)->andReturn($title);

    (new ImportTitle(TitleType::Movie, 603))->handle($action);
});
