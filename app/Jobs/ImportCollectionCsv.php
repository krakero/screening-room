<?php

namespace App\Jobs;

use App\Actions\Collection\AddCollectionItem;
use App\Actions\Tmdb\ImportTitle;
use App\Enums\CollectionFormat;
use App\Enums\TitleType;
use App\Models\CollectionItem;
use App\Models\Season;
use App\Models\Title;
use App\Services\Collection\CsvImporter;
use App\Services\Search\SearchTitles;
use App\Services\Tmdb\TmdbException;
use App\Support\IntegrationSettings;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Imports collection items from a CSV file: parses the file, matches each row
 * to a Title via TMDB search/import, creates CollectionItems, skips exact
 * duplicates, and stores a result summary for the settings page to display.
 */
class ImportCollectionCsv implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 300;

    public int $tries = 3;

    /**
     * @param  string  $path  Path to the uploaded CSV, relative to the `local` disk.
     */
    public function __construct(
        public readonly string $path,
    ) {}

    public function uniqueId(): string
    {
        return $this->path;
    }

    public function handle(
        CsvImporter $csvImporter,
        SearchTitles $searchTitles,
        ImportTitle $importTitle,
        AddCollectionItem $addCollectionItem,
        IntegrationSettings $settings,
    ): void {
        try {
            $settings->set('collection.last_import', [
                'status' => 'running',
                'started_at' => now()->toIso8601String(),
            ]);

            $fullPath = Storage::disk('local')->path($this->path);

            try {
                $parsed = $csvImporter->parse($fullPath);
            } catch (\InvalidArgumentException $exception) {
                $settings->set('collection.last_import', [
                    'status' => 'failed',
                    'finished_at' => now()->toIso8601String(),
                    'error' => $exception->getMessage(),
                ]);

                Storage::disk('local')->delete($this->path);

                return;
            }

            $imported = 0;
            $skipped = 0;
            $duplicates = 0;
            $unmatched = [];

            foreach ($parsed['rows'] as $index => $row) {
                $this->updateProgress($settings, $index + 1, count($parsed['rows']));

                try {
                    $title = $this->findOrImportTitle($row, $searchTitles, $importTitle);

                    if ($title === null) {
                        $unmatched[] = [
                            'row' => $row['row_number'],
                            'title' => $row['title'],
                            'year' => $row['year'],
                            'type' => $row['type']->value,
                            'reason' => 'No TMDB match found',
                        ];
                        $skipped++;

                        continue;
                    }

                    $seasonId = null;

                    if ($row['season'] !== null) {
                        $season = Season::where('title_id', $title->id)
                            ->where('season_number', $row['season'])
                            ->first();

                        if ($season === null) {
                            $unmatched[] = [
                                'row' => $row['row_number'],
                                'title' => $row['title'],
                                'year' => $row['year'],
                                'type' => $row['type']->value,
                                'season' => $row['season'],
                                'reason' => "Season {$row['season']} not found for this title",
                            ];
                            $skipped++;

                            continue;
                        }

                        $seasonId = $season->id;
                    }

                    if ($this->isDuplicate($title->id, $seasonId, $row)) {
                        $duplicates++;

                        continue;
                    }

                    $addCollectionItem->handle($title, [
                        'season_id' => $seasonId,
                        'format' => $row['format'],
                        'edition' => $row['edition'],
                        'retailer' => $row['retailer'],
                        'barcode' => $row['barcode'],
                        'acquired_at' => $row['acquired_at'],
                        'price' => $row['price'],
                        'currency' => $row['currency'],
                        'location' => $row['location'],
                        'notes' => $row['notes'],
                    ]);

                    $imported++;
                } catch (TmdbException $exception) {
                    $unmatched[] = [
                        'row' => $row['row_number'],
                        'title' => $row['title'],
                        'year' => $row['year'],
                        'type' => $row['type']->value,
                        'reason' => 'TMDB import failed: '.$exception->getMessage(),
                    ];
                    $skipped++;
                }
            }

            $settings->set('collection.last_import', [
                'status' => 'success',
                'finished_at' => now()->toIso8601String(),
                'summary' => [
                    'Imported' => $imported,
                    'Duplicates skipped' => $duplicates,
                    'Parse errors' => count($parsed['errors']),
                    'Unmatched' => count($unmatched),
                ],
                'parse_errors' => $parsed['errors'],
                'unmatched' => $unmatched,
            ]);

            Storage::disk('local')->delete($this->path);
        } catch (Throwable $exception) {
            $settings->set('collection.last_import', [
                'status' => 'failed',
                'finished_at' => now()->toIso8601String(),
                'error' => $exception->getMessage(),
            ]);

            throw $exception;
        }
    }

    /**
     * @param  array{title: string, year: ?int, type: TitleType, season: ?int, format: CollectionFormat, edition: ?string, retailer: ?string, barcode: ?string, acquired_at: ?string, price: ?float, currency: ?string, location: ?string, notes: ?string, row_number: int}  $row
     */
    private function findOrImportTitle(array $row, SearchTitles $searchTitles, ImportTitle $importTitle): ?Title
    {
        $query = $row['title'];

        if ($row['year'] !== null) {
            $query .= ' '.$row['year'];
        }

        $results = $searchTitles->handle($query);

        $match = $results->first(function (array $result) use ($row): bool {
            return $result['type'] === $row['type']
                && strtolower($result['name']) === strtolower($row['title'])
                && ($row['year'] === null || $result['year'] === (string) $row['year']);
        });

        if ($match !== null && $match['title'] !== null) {
            return $match['title'];
        }

        if ($match === null) {
            $match = $results->first(fn (array $result): bool => $result['type'] === $row['type']);
        }

        if ($match === null) {
            return null;
        }

        if ($match['title'] !== null) {
            return $match['title'];
        }

        return $importTitle->handle($match['type'], $match['tmdb_id']);
    }

    /**
     * @param  array{title: string, year: ?int, type: TitleType, season: ?int, format: CollectionFormat, edition: ?string, retailer: ?string, barcode: ?string, acquired_at: ?string, price: ?float, currency: ?string, location: ?string, notes: ?string, row_number: int}  $row
     */
    private function isDuplicate(int $titleId, ?int $seasonId, array $row): bool
    {
        return CollectionItem::where('title_id', $titleId)
            ->where('season_id', $seasonId)
            ->where('format', $row['format'])
            ->where('edition', $row['edition'])
            ->where('barcode', $row['barcode'])
            ->exists();
    }

    private function updateProgress(IntegrationSettings $settings, int $current, int $total): void
    {
        $state = $settings->get('collection.last_import');

        if (! is_array($state)) {
            return;
        }

        $settings->set('collection.last_import', [
            ...$state,
            'current' => $current,
            'total' => $total,
        ]);
    }
}
