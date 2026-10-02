<?php

namespace App\Console\Commands;

use App\Enums\TitleType;
use App\Jobs\ImportTitle;
use App\Jobs\RefreshTitleFromTmdb;
use App\Models\Title;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

class RefreshTmdbTitles extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'tmdb:refresh
        {--limit=200 : Maximum number of titles to refresh in this run}
        {--providers-missing : Refresh only titles whose watch providers have never been checked (respects --limit)}
        {--title= : Force-refresh a single title (by id or tmdb_id) regardless of staleness, ignoring --limit}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Dispatch TMDB import jobs to refresh stale titles';

    public function handle(): int
    {
        if ($this->option('title') !== null) {
            return $this->refreshOne($this->option('title'));
        }

        $limit = (int) $this->option('limit');

        if ($this->option('providers-missing')) {
            return $this->refreshMissingProviders($limit);
        }

        $dailyCutoff = Carbon::now()->subDay();
        $weeklyCutoff = Carbon::now()->subWeek();

        $airing = Title::query()
            ->where('type', TitleType::Show)
            ->where(function (Builder $query): void {
                $query->where('in_production', true)->orWhere('status', 'Returning Series');
            })
            ->where(function (Builder $query) use ($dailyCutoff): void {
                $query->whereNull('tmdb_synced_at')->orWhere('tmdb_synced_at', '<', $dailyCutoff);
            })
            ->orderBy('tmdb_synced_at')
            ->limit($limit)
            ->get();

        $remaining = $limit - $airing->count();

        $everythingElse = $remaining > 0
            ? Title::query()
                ->where(function (Builder $query): void {
                    $query->where('type', TitleType::Movie)
                        ->orWhere(function (Builder $query): void {
                            $query->where('type', TitleType::Show)
                                ->where('in_production', false)
                                ->where(function (Builder $query): void {
                                    $query->whereNull('status')->orWhere('status', '!=', 'Returning Series');
                                });
                        });
                })
                ->where(function (Builder $query) use ($weeklyCutoff): void {
                    $query->whereNull('tmdb_synced_at')->orWhere('tmdb_synced_at', '<', $weeklyCutoff);
                })
                ->orderBy('tmdb_synced_at')
                ->limit($remaining)
                ->get()
            : collect();

        $titles = $airing->concat($everythingElse);

        foreach ($titles as $title) {
            ImportTitle::dispatch($title->type, $title->tmdb_id);
        }

        $this->info("Dispatched {$titles->count()} TMDB refresh jobs ({$airing->count()} airing, {$everythingElse->count()} other).");

        return self::SUCCESS;
    }

    /**
     * Dispatches an import for titles that have never had their watch providers checked,
     * ignoring staleness. Used to backfill providers for titles imported before they were tracked.
     */
    private function refreshMissingProviders(int $limit): int
    {
        $titles = Title::query()
            ->whereNull('watch_providers_checked_at')
            ->orderBy('id')
            ->limit($limit)
            ->get();

        foreach ($titles as $title) {
            ImportTitle::dispatch($title->type, $title->tmdb_id);
        }

        $this->info("Dispatched {$titles->count()} TMDB refresh jobs for titles missing watch providers.");

        return self::SUCCESS;
    }

    /**
     * Force-refreshes one title (by internal id, falling back to tmdb_id) regardless of
     * staleness. Ignores `--limit` entirely — this is a targeted, single-title refresh.
     */
    private function refreshOne(string $identifier): int
    {
        $title = Title::find($identifier) ?? Title::where('tmdb_id', $identifier)->first();

        if ($title === null) {
            $this->error("No title found with id or tmdb_id \"{$identifier}\".");

            return self::FAILURE;
        }

        RefreshTitleFromTmdb::dispatch($title->id);

        $this->info("Dispatched a forced TMDB refresh for \"{$title->name}\" (id {$title->id}, tmdb_id {$title->tmdb_id}).");

        return self::SUCCESS;
    }
}
