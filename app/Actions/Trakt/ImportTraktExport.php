<?php

namespace App\Actions\Trakt;

use App\Actions\Trakt\DataTransferObjects\CustomListsImportResult;
use App\Actions\Trakt\DataTransferObjects\PlaysImportResult;
use App\Actions\Trakt\DataTransferObjects\RatingsImportResult;
use App\Actions\Trakt\DataTransferObjects\TitlesImportResult;
use App\Actions\Trakt\DataTransferObjects\TraktImportSummary;
use App\Actions\Trakt\DataTransferObjects\WatchlistImportResult;
use App\Services\Trakt\ExportReader;

/**
 * Orchestrates a full (or partial) import of a Trakt personal data export:
 * collects the referenced TMDB titles, imports the ones missing from the
 * database, then imports plays/ratings/watchlist/custom lists.
 */
class ImportTraktExport
{
    /**
     * @var array<int, string>
     */
    public const SECTIONS = ['history', 'ratings', 'watchlist', 'lists'];

    public function __construct(
        private readonly CollectTitleReferences $collectTitleReferences,
        private readonly ImportMissingTitles $importMissingTitles,
        private readonly ImportPlays $importPlays,
        private readonly ImportRatings $importRatings,
        private readonly ImportWatchlist $importWatchlist,
        private readonly ImportCustomLists $importCustomLists,
    ) {}

    /**
     * @param  array<int, string>  $only  Subset of self::SECTIONS; empty means all of them.
     */
    public function handle(string $path, bool $dryRun = false, array $only = [], ?callable $onTitleProgress = null): TraktImportSummary
    {
        $only = $only === [] ? self::SECTIONS : $only;

        $reader = new ExportReader($path);

        try {
            $collected = $this->collectTitleReferences->handle($reader, $only);

            $titlesResult = $this->importMissingTitles->handle($collected['references'], $dryRun, $onTitleProgress);
            $titles = new TitlesImportResult(
                $titlesResult->imported,
                $titlesResult->alreadyPresent,
                array_merge($titlesResult->skipped, $collected['skipped']),
            );

            $plays = in_array('history', $only, true)
                ? $this->importPlays->handle($reader->history(), $dryRun)
                : new PlaysImportResult(0, 0);

            $ratings = in_array('ratings', $only, true)
                ? $this->importRatings->handle($reader, $dryRun)
                : new RatingsImportResult(0, 0);

            $watchlist = in_array('watchlist', $only, true)
                ? $this->importWatchlist->handle($reader, $dryRun)
                : new WatchlistImportResult(0, 0);

            $lists = in_array('lists', $only, true)
                ? $this->importCustomLists->handle($reader, $dryRun)
                : new CustomListsImportResult(0, 0, 0);

            return new TraktImportSummary($dryRun, $titles, $plays, $ratings, $watchlist, $lists);
        } finally {
            $reader->cleanup();
        }
    }
}
