<?php

use App\Jobs\ImportTraktExport;
use App\Jobs\RetryFailedTraktTitles;
use App\Models\User;
use App\Support\IntegrationSettings;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

test('the old integrations route redirects to features', function () {
    $this->actingAs(User::factory()->create());

    $response = $this->get('/settings/integrations/trakt');

    $response->assertRedirect('/settings/features/trakt');
});

test('guests are redirected to the login page', function () {
    $response = $this->get(route('settings.features.trakt'));

    $response->assertRedirect(route('login'));
});

test('the trakt import page can be rendered', function () {
    $this->actingAs(User::factory()->create());

    $response = $this->get(route('settings.features.trakt'));

    $response->assertOk();
    $response->assertSee('Trakt import');
    $response->assertSee('Watch history → plays');
    $response->assertDontSee('@if');
});

test('a trakt export can be uploaded and dispatches the import job', function () {
    Storage::fake('local');
    Queue::fake();

    $this->actingAs(User::factory()->create());

    Livewire::test('pages::settings.features.trakt')
        ->set('traktFile', UploadedFile::fake()->create('export.zip', 100))
        ->call('importTrakt')
        ->assertHasNoErrors();

    $storedPath = collect(Storage::disk('local')->files('imports'))->first();

    Storage::disk('local')->assertExists($storedPath);

    Queue::assertPushed(
        ImportTraktExport::class,
        fn (ImportTraktExport $job): bool => $job->path === $storedPath,
    );
});

test('a successful last import summary is shown', function () {
    app(IntegrationSettings::class)->set('trakt.last_import', [
        'status' => 'success',
        'finished_at' => '2026-01-01T00:00:00+00:00',
        'summary' => [
            'titles_total' => 3,
            'failed_jobs' => 0,
        ],
    ]);

    $this->actingAs(User::factory()->create());

    $response = $this->get(route('settings.features.trakt'));

    $response->assertOk();
    $response->assertSee('Last import: succeeded');
    $response->assertSee('titles_total: 3');
    $response->assertDontSee('@if');
});

test('a running import shows progress and can be cancelled', function () {
    app(IntegrationSettings::class)->set('trakt.last_import', [
        'status' => 'running',
        'batch_id' => 'fake-batch-id',
        'phase' => 'titles',
        'current' => 340,
        'total' => 1389,
    ]);

    $this->actingAs(User::factory()->create());

    $response = $this->get(route('settings.features.trakt'));

    $response->assertOk();
    $response->assertSee('Importing titles 340/1389');
    $response->assertSee('Cancel');
    $response->assertDontSee('@if');
});

test('a completed-with-errors last import summary is shown', function () {
    app(IntegrationSettings::class)->set('trakt.last_import', [
        'status' => 'completed_with_errors',
        'finished_at' => '2026-01-01T00:00:00+00:00',
        'summary' => [
            'titles_total' => 3,
            'failed_jobs' => 1,
        ],
    ]);

    $this->actingAs(User::factory()->create());

    $response = $this->get(route('settings.features.trakt'));

    $response->assertOk();
    $response->assertSee('Last import: finished with some errors');
    $response->assertSee('titles_total: 3');
    $response->assertDontSee('@if');
});

test('an incomplete last import (tail never ran) is shown as failed', function () {
    app(IntegrationSettings::class)->set('trakt.last_import', [
        'status' => 'failed_incomplete',
        'finished_at' => '2026-01-01T00:00:00+00:00',
    ]);

    $this->actingAs(User::factory()->create());

    $response = $this->get(route('settings.features.trakt'));

    $response->assertOk();
    $response->assertSee('Last import: stopped before finishing');
    $response->assertDontSee('@if');
});

test('a failed last import error is shown', function () {
    app(IntegrationSettings::class)->set('trakt.last_import', [
        'status' => 'failed',
        'finished_at' => '2026-01-01T00:00:00+00:00',
        'error' => 'Could not locate a Trakt export.',
    ]);

    $this->actingAs(User::factory()->create());

    $response = $this->get(route('settings.features.trakt'));

    $response->assertOk();
    $response->assertSee('Last import: failed');
    $response->assertSee('Could not locate a Trakt export.');
    $response->assertDontSee('@if');
});

test('failed titles are listed with a retry button when the import finished with errors', function () {
    Storage::fake('local');
    Storage::disk('local')->put('imports/export.zip', 'fake export contents');

    app(IntegrationSettings::class)->set('trakt.last_import', [
        'status' => 'completed_with_errors',
        'finished_at' => '2026-01-01T00:00:00+00:00',
        'summary' => ['titles_total' => 2, 'failed_jobs' => 1],
        'export_path' => 'imports/export.zip',
        'dry_run' => false,
        'failed_titles' => [
            'movie:603' => ['type' => 'movie', 'tmdb_id' => 603, 'error' => 'The job failed permanently.'],
        ],
    ]);

    $this->actingAs(User::factory()->create());

    $response = $this->get(route('settings.features.trakt'));

    $response->assertOk();
    $response->assertSee('1 title failed to import');
    $response->assertSee('movie tmdb:603');
    $response->assertSee('The job failed permanently.');
    $response->assertSee('Retry failed');
    $response->assertDontSee('@if');
});

test('retrying failed titles dispatches a job with just those titles and clears no state until it runs', function () {
    Storage::fake('local');
    Queue::fake();
    Storage::disk('local')->put('imports/export.zip', 'fake export contents');

    app(IntegrationSettings::class)->set('trakt.last_import', [
        'status' => 'completed_with_errors',
        'finished_at' => '2026-01-01T00:00:00+00:00',
        'export_path' => 'imports/export.zip',
        'dry_run' => false,
        'failed_titles' => [
            'movie:603' => ['type' => 'movie', 'tmdb_id' => 603, 'error' => 'The job failed permanently.'],
        ],
    ]);

    $this->actingAs(User::factory()->create());

    Livewire::test('pages::settings.features.trakt')
        ->call('retryFailedTitles')
        ->assertHasNoErrors();

    Queue::assertPushed(
        RetryFailedTraktTitles::class,
        fn (RetryFailedTraktTitles $job): bool => $job->path === 'imports/export.zip'
            && $job->references === [['type' => 'movie', 'tmdb_id' => 603]],
    );
});

test('retrying failed titles is refused when the original export is no longer available', function () {
    Storage::fake('local');
    Queue::fake();

    app(IntegrationSettings::class)->set('trakt.last_import', [
        'status' => 'completed_with_errors',
        'finished_at' => '2026-01-01T00:00:00+00:00',
        'export_path' => 'imports/export.zip',
        'failed_titles' => [
            'movie:603' => ['type' => 'movie', 'tmdb_id' => 603, 'error' => 'The job failed permanently.'],
        ],
    ]);

    $this->actingAs(User::factory()->create());

    Livewire::test('pages::settings.features.trakt')
        ->call('retryFailedTitles')
        ->assertHasNoErrors();

    Queue::assertNotPushed(RetryFailedTraktTitles::class);
});
