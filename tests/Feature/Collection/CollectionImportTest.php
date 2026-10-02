<?php

use App\Actions\Collection\AddCollectionItem;
use App\Actions\Tmdb\ImportTitle;
use App\Enums\CollectionFormat;
use App\Jobs\ImportCollectionCsv;
use App\Models\CollectionItem;
use App\Models\Title;
use App\Models\User;
use App\Services\Collection\CsvImporter;
use App\Services\Search\SearchTitles;
use App\Support\IntegrationSettings;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

beforeEach(function () {
    Storage::fake('local');
    $user = User::factory()->create(['collection_enabled' => true]);
    actingAs($user);
});

test('import job creates collection items for matched titles', function () {
    $title = Title::factory()->movie()->create([
        'name' => 'The Matrix',
        'release_date' => '1999-03-31',
    ]);

    $csv = tmpCsv([
        'title,year,format',
        'The Matrix,1999,4K',
    ]);

    Storage::disk('local')->put('test.csv', file_get_contents($csv));

    $job = new ImportCollectionCsv('test.csv');
    $job->handle(
        app(CsvImporter::class),
        app(SearchTitles::class),
        app(ImportTitle::class),
        app(AddCollectionItem::class),
        app(IntegrationSettings::class),
    );

    expect(CollectionItem::count())->toBe(1);

    $item = CollectionItem::first();
    expect($item->title_id)->toBe($title->id);
    expect($item->format)->toBe(CollectionFormat::Uhd4k);
})->skip('Requires TMDB mocking');

test('import job skips exact duplicates', function () {
    $title = Title::factory()->movie()->create([
        'name' => 'The Matrix',
    ]);

    CollectionItem::factory()->for($title)->create([
        'format' => CollectionFormat::Uhd4k,
        'edition' => 'Collector\'s Edition',
        'barcode' => '123456',
    ]);

    $csv = tmpCsv([
        'title,format,edition,barcode',
        'The Matrix,4K,Collector\'s Edition,123456',
    ]);

    Storage::disk('local')->put('test.csv', file_get_contents($csv));

    $job = new ImportCollectionCsv('test.csv');
    $job->handle(
        app(CsvImporter::class),
        app(SearchTitles::class),
        app(ImportTitle::class),
        app(AddCollectionItem::class),
        app(IntegrationSettings::class),
    );

    expect(CollectionItem::count())->toBe(1);

    $summary = app(IntegrationSettings::class)->get('collection.last_import')['summary'];
    expect($summary['Duplicates skipped'])->toBe(1);
})->skip('Requires TMDB mocking');

test('import job reports unmatched titles', function () {
    $csv = tmpCsv([
        'title,year,format',
        'Nonexistent Movie,2099,4K',
    ]);

    Storage::disk('local')->put('test.csv', file_get_contents($csv));

    $job = new ImportCollectionCsv('test.csv');
    $job->handle(
        app(CsvImporter::class),
        app(SearchTitles::class),
        app(ImportTitle::class),
        app(AddCollectionItem::class),
        app(IntegrationSettings::class),
    );

    expect(CollectionItem::count())->toBe(0);

    $result = app(IntegrationSettings::class)->get('collection.last_import');
    expect($result['unmatched'])->toHaveCount(1);
    expect($result['unmatched'][0]['title'])->toBe('Nonexistent Movie');
})->skip('Requires TMDB mocking');

test('api upload endpoint dispatches job', function () {
    Queue::fake();

    $file = UploadedFile::fake()->createWithContent('collection.csv', "title,format\nThe Matrix,4K");

    $response = $this->postJson('/api/v1/collection/import', [
        'file' => $file,
    ]);

    $response->assertStatus(202);

    Queue::assertPushed(ImportCollectionCsv::class);
});

test('api upload validates file type', function () {
    $file = UploadedFile::fake()->create('collection.txt', 100, 'text/plain');

    $response = $this->postJson('/api/v1/collection/import', [
        'file' => $file,
    ]);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors('file');
});

test('api upload requires file', function () {
    $response = $this->postJson('/api/v1/collection/import', []);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors('file');
});

test('api upload validates file size', function () {
    $file = UploadedFile::fake()->create('collection.csv', 11000);

    $response = $this->postJson('/api/v1/collection/import', [
        'file' => $file,
    ]);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors('file');
});

test('livewire component dispatches job on upload', function () {
    Queue::fake();

    $file = UploadedFile::fake()->createWithContent('collection.csv', "title,format\nThe Matrix,4K");

    Livewire::test('pages::settings.features.collection-import')
        ->set('csvFile', $file)
        ->call('importCsv')
        ->assertHasNoErrors();

    Queue::assertPushed(ImportCollectionCsv::class);
});
