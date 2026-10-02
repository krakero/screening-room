<?php

namespace App\Console\Commands;

use App\Actions\Trakt\ImportTraktExport;
use App\Services\Trakt\TraktExportException;
use Illuminate\Console\Command;

class ImportTrakt extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'trakt:import
        {path : Path to a Trakt export .zip file or an already-extracted export directory}
        {--dry-run : Parse the export and report counts without writing to the database or TMDB}
        {--only= : Comma-separated subset of sections to import: history,ratings,watchlist,lists}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Import a Trakt personal data export (history, ratings, watchlist, and custom lists)';

    public function handle(ImportTraktExport $importTraktExport): int
    {
        $path = (string) $this->argument('path');
        $dryRun = (bool) $this->option('dry-run');
        $only = $this->parseOnly();

        if ($only !== [] && array_diff($only, ImportTraktExport::SECTIONS) !== []) {
            $this->components->error('--only must be a comma-separated subset of: '.implode(', ', ImportTraktExport::SECTIONS));

            return self::INVALID;
        }

        $this->components->info($dryRun ? 'Dry run: parsing export, no writes will happen.' : 'Importing Trakt export…');

        $bar = $this->output->createProgressBar();
        $bar->setFormat('Resolving titles: [%bar%] %current% checked');
        $bar->start();

        try {
            $summary = $importTraktExport->handle($path, $dryRun, $only, function () use ($bar): void {
                $bar->advance();
            });
        } catch (TraktExportException $exception) {
            $bar->finish();
            $this->newLine();
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $bar->finish();
        $this->newLine(2);

        $this->table(
            ['Section', 'Imported', 'Skipped'],
            collect($summary->rows())->map(fn (array $row): array => [$row['label'], $row['imported'], $row['skipped']])->all(),
        );

        if ($summary->titles->skipped !== []) {
            $this->newLine();
            $this->components->warn(count($summary->titles->skipped).' title(s) could not be resolved:');

            foreach (array_slice($summary->titles->skipped, 0, 20) as $skipped) {
                $this->line("  - {$skipped->context}: {$skipped->reason}");
            }

            if (count($summary->titles->skipped) > 20) {
                $this->line('  … and '.(count($summary->titles->skipped) - 20).' more.');
            }
        }

        $this->newLine();
        $this->components->info($dryRun ? 'Dry run complete. No changes were made.' : 'Import complete.');

        return self::SUCCESS;
    }

    /**
     * @return array<int, string>
     */
    private function parseOnly(): array
    {
        $only = $this->option('only');

        if ($only === null) {
            return [];
        }

        return collect(explode(',', $only))
            ->map(fn (string $section): string => trim($section))
            ->filter()
            ->values()
            ->all();
    }
}
