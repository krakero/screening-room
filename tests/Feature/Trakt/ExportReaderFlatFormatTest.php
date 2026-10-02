<?php

use App\Services\Trakt\ExportReader;
use App\Services\Trakt\TraktExportException;

/**
 * Builds a movie history entry for a given page/index, so entries can be
 * traced back to the page they came from.
 */
function flatHistoryEntry(int $id): array
{
    return [
        'id' => $id,
        'watched_at' => '2024-01-01T00:00:00.000Z',
        'action' => 'scrobble',
        'type' => 'movie',
        'movie' => [
            'title' => "Movie {$id}",
            'year' => 2020,
            'ids' => ['trakt' => $id, 'slug' => "movie-{$id}", 'imdb' => "tt{$id}", 'tmdb' => 1000 + $id],
        ],
    ];
}

function flatRatingEntry(int $id): array
{
    return [
        'rated_at' => '2024-01-01T00:00:00.000Z',
        'rating' => 8,
        'type' => 'movie',
        'movie' => [
            'title' => "Movie {$id}",
            'year' => 2020,
            'ids' => ['trakt' => $id, 'slug' => "movie-{$id}", 'imdb' => "tt{$id}", 'tmdb' => 1000 + $id],
        ],
    ];
}

function flatWatchlistEntry(int $id): array
{
    return [
        'rank' => $id,
        'id' => $id,
        'listed_at' => '2024-01-01T00:00:00.000Z',
        'notes' => null,
        'my_rating' => null,
        'type' => 'movie',
        'movie' => [
            'title' => "Watchlist Movie {$id}",
            'year' => 2020,
            'ids' => ['trakt' => $id, 'slug' => "watchlist-movie-{$id}", 'imdb' => "tt9{$id}", 'tmdb' => 2000 + $id],
        ],
    ];
}

function flatListsMetadata(): array
{
    return [[
        'name' => 'Top List',
        'description' => 'A short test list',
        'ids' => ['trakt' => 9001, 'slug' => 'top-list'],
        'images' => ['posters' => []],
    ]];
}

/**
 * @param  array<string, mixed>  $files  relative path (without a leading slash) => value to json_encode
 */
function buildTraktZip(array $files, ?string $prefix = null): string
{
    $zipPath = sys_get_temp_dir().'/trakt-export-flat-'.uniqid().'.zip';

    $zip = new ZipArchive;
    $zip->open($zipPath, ZipArchive::CREATE);

    foreach ($files as $relative => $value) {
        $path = $prefix !== null ? "{$prefix}/{$relative}" : $relative;
        $zip->addFromString($path, json_encode($value));
    }

    $zip->close();

    return $zipPath;
}

function assertTraktExportSummary(ExportReader $reader): void
{
    expect(iterator_to_array($reader->history()))->toHaveCount(7)
        ->and(iterator_to_array($reader->ratedTitles()))->toHaveCount(5)
        ->and($reader->watchlist())->toHaveCount(2);

    $lists = iterator_to_array($reader->customLists());

    expect($lists)->toHaveCount(1)
        ->and($lists[0]['slug'])->toBe('top-list')
        ->and($lists[0]['items'])->toHaveCount(2);
}

test('it reads the new flat, paginated export layout', function () {
    $zipPath = buildTraktZip([
        'watched-history-1.json' => [flatHistoryEntry(1), flatHistoryEntry(2), flatHistoryEntry(3)],
        'watched-history-2.json' => [flatHistoryEntry(4), flatHistoryEntry(5), flatHistoryEntry(6)],
        'watched-history-3.json' => [flatHistoryEntry(7)],
        'ratings-movies-1.json' => [flatRatingEntry(1), flatRatingEntry(2), flatRatingEntry(3)],
        'ratings-movies-2.json' => [flatRatingEntry(4), flatRatingEntry(5)],
        'ratings-shows.json' => [],
        'lists-watchlist.json' => [flatWatchlistEntry(1), flatWatchlistEntry(2)],
        'lists-lists.json' => flatListsMetadata(),
        'lists-list-9001-top-list.json' => [flatWatchlistEntry(101), flatWatchlistEntry(102)],
    ]);

    assertTraktExportSummary(new ExportReader($zipPath));

    unlink($zipPath);
});

test('it reads the old folder export layout', function () {
    $zipPath = buildTraktZip([
        'watched/history-1.json' => [flatHistoryEntry(1), flatHistoryEntry(2), flatHistoryEntry(3)],
        'watched/history-2.json' => [flatHistoryEntry(4), flatHistoryEntry(5), flatHistoryEntry(6)],
        'watched/history-3.json' => [flatHistoryEntry(7)],
        'ratings/ratings-movies.json' => [flatRatingEntry(1), flatRatingEntry(2), flatRatingEntry(3), flatRatingEntry(4), flatRatingEntry(5)],
        'ratings/ratings-shows.json' => [],
        'lists/watchlist.json' => [flatWatchlistEntry(1), flatWatchlistEntry(2)],
        'lists/lists.json' => flatListsMetadata(),
        'lists/list-9001-top-list.json' => [flatWatchlistEntry(101), flatWatchlistEntry(102)],
    ]);

    assertTraktExportSummary(new ExportReader($zipPath));

    unlink($zipPath);
});

test('it reads the old folder export layout nested under a username directory', function () {
    $zipPath = buildTraktZip([
        'watched/history-1.json' => [flatHistoryEntry(1), flatHistoryEntry(2), flatHistoryEntry(3)],
        'watched/history-2.json' => [flatHistoryEntry(4), flatHistoryEntry(5), flatHistoryEntry(6)],
        'watched/history-3.json' => [flatHistoryEntry(7)],
        'ratings/ratings-movies.json' => [flatRatingEntry(1), flatRatingEntry(2), flatRatingEntry(3), flatRatingEntry(4), flatRatingEntry(5)],
        'ratings/ratings-shows.json' => [],
        'lists/watchlist.json' => [flatWatchlistEntry(1), flatWatchlistEntry(2)],
        'lists/lists.json' => flatListsMetadata(),
        'lists/list-9001-top-list.json' => [flatWatchlistEntry(101), flatWatchlistEntry(102)],
    ], prefix: 'vmitchell85');

    assertTraktExportSummary(new ExportReader($zipPath));

    unlink($zipPath);
});

test('it merges paginated pages in numeric, not lexical, order', function () {
    $zipPath = buildTraktZip([
        'ratings-movies-1.json' => [flatRatingEntry(1), flatRatingEntry(2)],
        'ratings-movies-2.json' => [flatRatingEntry(3)],
        'ratings-movies-10.json' => [flatRatingEntry(4)],
        'lists-watchlist.json' => [],
        'lists-lists.json' => [],
    ]);

    $reader = new ExportReader($zipPath);

    $ids = array_map(fn (array $entry) => $entry['movie']['ids']['trakt'], iterator_to_array($reader->ratedTitles()));

    expect($ids)->toBe([1, 2, 3, 4]);

    unlink($zipPath);
});

test('it lists what it found when the export root cannot be located', function () {
    $empty = sys_get_temp_dir().'/trakt-export-empty-'.uniqid();
    mkdir($empty, recursive: true);
    file_put_contents($empty.'/user-profile.json', '{}');

    try {
        new ExportReader($empty);
    } catch (TraktExportException $exception) {
        expect($exception->getMessage())->toContain('user-profile.json');
    }

    unlink($empty.'/user-profile.json');
    rmdir($empty);
});
