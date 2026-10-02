<?php

namespace App\Console\Commands;

use App\Actions\Plex\ResolvePlexAvailability;
use App\Models\Episode;
use App\Models\PlexItem;
use App\Models\Title;
use App\Support\IntegrationSettings;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class RefreshPlexAvailability extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'plex:refresh-availability {--force : Re-resolve every cached item, not just stale ones}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Re-checks cached Plex availability (plex_items) against the server, refreshing stale entries.';

    /**
     * @var array{checked: int, found: int, not_found: int, errors: int, skipped_fresh: int}
     */
    private array $counts = ['checked' => 0, 'found' => 0, 'not_found' => 0, 'errors' => 0, 'skipped_fresh' => 0];

    public function handle(IntegrationSettings $settings, ResolvePlexAvailability $resolver): int
    {
        if (! $settings->configured('plex.url', 'plex.token')) {
            $this->components->warn('Plex is not configured; skipping.');

            return self::SUCCESS;
        }

        $force = (bool) $this->option('force');
        $startedAt = Carbon::now();

        $query = PlexItem::query()->with(['plexable' => function ($morphTo): void {
            $morphTo->morphWith([Episode::class => ['title']]);
        }]);

        if (! $force) {
            $query->where('checked_at', '<', now()->subDay());
        }

        $items = $query->get()->filter(
            fn (PlexItem $item): bool => $item->plexable instanceof Title || $item->plexable instanceof Episode
        );

        if ($items->isEmpty()) {
            $this->components->info('Nothing to refresh.');

            return self::SUCCESS;
        }

        $titleItems = $items->filter(fn (PlexItem $item): bool => $item->plexable instanceof Title);

        /** @var Collection<int, Collection<int, Episode>> $episodesByShow */
        $episodesByShow = $items->filter(fn (PlexItem $item): bool => $item->plexable instanceof Episode)
            ->map(fn (PlexItem $item): Episode => $item->plexable)
            ->groupBy(fn (Episode $episode): int => $episode->title_id);

        $bar = $this->output->createProgressBar($items->count());
        $bar->setFormat(" %current%/%max% [%bar%] %message%\n");
        $bar->setMessage('Starting…');
        $bar->start();

        foreach ($titleItems as $item) {
            /** @var Title $title */
            $title = $item->plexable;
            $bar->setMessage($title->name);

            $result = $resolver->forTitle($title, force: $force);
            $this->tally('checked', $result?->found() ?? false);

            $bar->advance();
        }

        foreach ($episodesByShow as $episodes) {
            /** @var Title $show */
            $show = $episodes->first()->title;
            $bar->setMessage($show->name);

            $results = $resolver->refreshEpisodesForShow($show, $episodes, force: $force);

            foreach ($episodes as $episode) {
                $result = $results->get($episode->id);
                $bar->setMessage(sprintf('%s S%02dE%02d', $show->name, $episode->season_number, $episode->episode_number));

                match ($result['outcome'] ?? 'error') {
                    'found' => $this->tally('checked', true),
                    'not_found' => $this->tally('checked', false),
                    'skipped_fresh' => $this->counts['skipped_fresh']++,
                    default => $this->counts['errors']++,
                };

                $bar->advance();
            }
        }

        $bar->finish();
        $this->newLine();

        $this->components->twoColumnDetail('Checked', (string) $this->counts['checked']);
        $this->components->twoColumnDetail('Found', (string) $this->counts['found']);
        $this->components->twoColumnDetail('Not found', (string) $this->counts['not_found']);
        $this->components->twoColumnDetail('Errors', (string) $this->counts['errors']);
        $this->components->twoColumnDetail('Skipped (already fresh)', (string) $this->counts['skipped_fresh']);
        $this->components->twoColumnDetail('Elapsed', $startedAt->diffForHumans(syntax: Carbon::DIFF_ABSOLUTE, short: true));

        return self::SUCCESS;
    }

    private function tally(string $key, bool $found): void
    {
        $this->counts[$key]++;
        $this->counts[$found ? 'found' : 'not_found']++;
    }
}
