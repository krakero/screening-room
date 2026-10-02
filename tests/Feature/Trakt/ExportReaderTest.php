<?php

use App\Services\Trakt\ExportReader;
use App\Services\Trakt\TraktExportException;

function sampleTraktExportPath(): string
{
    return base_path('tests/Fixtures/trakt/sample');
}

test('it reads history entries from a directory', function () {
    $reader = new ExportReader(sampleTraktExportPath());

    $entries = iterator_to_array($reader->history());

    expect($entries)->toHaveCount(5)
        ->and($entries[0]['id'])->toBe(5001)
        ->and($entries[0]['type'])->toBe('movie');
});

test('it reads ratings, watchlist, and custom lists', function () {
    $reader = new ExportReader(sampleTraktExportPath());

    expect(iterator_to_array($reader->ratedTitles()))->toHaveCount(3)
        ->and(iterator_to_array($reader->ratedEpisodes()))->toHaveCount(1)
        ->and($reader->watchlist())->toHaveCount(2);

    $lists = iterator_to_array($reader->customLists());

    expect($lists)->toHaveCount(1)
        ->and($lists[0]['slug'])->toBe('top-list')
        ->and($lists[0]['items'])->toHaveCount(2);
});

test('it locates the export root inside a nested extracted directory', function () {
    $nested = sys_get_temp_dir().'/trakt-export-nested-'.uniqid();
    mkdir($nested.'/vmitchell85', recursive: true);
    symlink(sampleTraktExportPath().'/watched', $nested.'/vmitchell85/watched');
    symlink(sampleTraktExportPath().'/lists', $nested.'/vmitchell85/lists');
    symlink(sampleTraktExportPath().'/ratings', $nested.'/vmitchell85/ratings');

    $reader = new ExportReader($nested);

    expect(iterator_to_array($reader->history()))->toHaveCount(5);

    unlink($nested.'/vmitchell85/watched');
    unlink($nested.'/vmitchell85/lists');
    unlink($nested.'/vmitchell85/ratings');
    rmdir($nested.'/vmitchell85');
    rmdir($nested);
});

test('it reads a zipped export', function () {
    $zipPath = sys_get_temp_dir().'/trakt-export-'.uniqid().'.zip';

    $zip = new ZipArchive;
    $zip->open($zipPath, ZipArchive::CREATE);

    $source = sampleTraktExportPath();
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($source, RecursiveDirectoryIterator::SKIP_DOTS),
    );

    foreach ($files as $file) {
        $relative = 'vmitchell85/'.substr((string) $file->getPathname(), strlen($source) + 1);
        $zip->addFile($file->getPathname(), $relative);
    }

    $zip->close();

    $reader = new ExportReader($zipPath);

    expect(iterator_to_array($reader->history()))->toHaveCount(5)
        ->and($reader->watchlist())->toHaveCount(2);

    unlink($zipPath);
});

test('it throws for a path that does not exist', function () {
    expect(fn () => new ExportReader('/nonexistent/path/'.uniqid()))
        ->toThrow(TraktExportException::class);
});

test('it throws when no export root can be found', function () {
    $empty = sys_get_temp_dir().'/trakt-export-empty-'.uniqid();
    mkdir($empty, recursive: true);

    expect(fn () => new ExportReader($empty))->toThrow(TraktExportException::class);

    rmdir($empty);
});

test('it locates a real-world export zip: top-level username folder, .DS_Store, and __MACOSX junk', function () {
    $zipPath = sys_get_temp_dir().'/trakt-export-real-'.uniqid().'.zip';

    $zip = new ZipArchive;
    $zip->open($zipPath, ZipArchive::CREATE);

    $zip->addFromString('vmitchell85/.DS_Store', 'junk');
    $zip->addFromString('vmitchell85/watched/history-1.json', json_encode([
        ['id' => 1, 'watched_at' => '2024-01-01T00:00:00.000Z', 'action' => 'scrobble', 'type' => 'movie', 'movie' => [
            'title' => 'Tiny Movie', 'year' => 2021, 'ids' => ['trakt' => 1, 'slug' => 'tiny-movie-2021', 'imdb' => 'tt0000001', 'tmdb' => 5001],
        ]],
    ]));
    $zip->addFromString('vmitchell85/lists/watchlist.json', json_encode([]));
    $zip->addFromString('__MACOSX/vmitchell85/watched/._history-1.json', 'resource fork junk');

    $zip->close();

    $reader = new ExportReader($zipPath);

    expect(iterator_to_array($reader->history()))->toHaveCount(1)
        ->and($reader->watchlist())->toBe([]);

    unlink($zipPath);
});

test('it supports a partial export with no lists directory', function () {
    $partial = sys_get_temp_dir().'/trakt-export-partial-'.uniqid();
    mkdir($partial.'/watched', recursive: true);
    file_put_contents($partial.'/watched/history-1.json', json_encode([
        ['id' => 1, 'watched_at' => '2024-01-01T00:00:00.000Z', 'action' => 'scrobble', 'type' => 'movie', 'movie' => [
            'title' => 'Tiny Movie', 'year' => 2021, 'ids' => ['trakt' => 1, 'slug' => 'tiny-movie-2021', 'imdb' => 'tt0000001', 'tmdb' => 5001],
        ]],
    ]));

    $reader = new ExportReader($partial);

    expect(iterator_to_array($reader->history()))->toHaveCount(1)
        ->and($reader->watchlist())->toBe([])
        ->and(iterator_to_array($reader->customLists()))->toBe([]);

    unlink($partial.'/watched/history-1.json');
    rmdir($partial.'/watched');
    rmdir($partial);
});
