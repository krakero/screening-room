<?php

namespace App\Console\Commands;

use App\Jobs\RefreshTitleRatings;
use App\Models\Title;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;

class RefreshRatings extends Command
{
    /**
     * MDBList's free tier allows 1,000 requests/day. The hourly schedule refreshes this many
     * titles per run (40 × 24 = 960/day), leaving headroom for on-demand lookups from title pages.
     */
    public const HOURLY_LIMIT = 40;

    /**
     * Seconds between dispatched jobs, so a batch trickles out instead of bursting MDBList.
     */
    public const DISPATCH_SPACING_SECONDS = 5;

    /**
     * Days before a checked title's ratings are considered stale.
     */
    public const STALE_AFTER_DAYS = 7;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'ratings:refresh {--limit=40 : Maximum number of titles to refresh in this run}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Dispatch MDBList jobs to refresh stale external ratings for a batch of titles. Never-checked titles first (newest first), then the stalest. Scheduled hourly; also usable for manual backfills.';

    public function handle(): int
    {
        $limit = (int) $this->option('limit');

        $titles = $this->titlesToRefresh($limit);

        foreach ($titles->values() as $index => $title) {
            RefreshTitleRatings::dispatch($title)->delay(now()->addSeconds($index * self::DISPATCH_SPACING_SECONDS));
        }

        $this->info("Dispatched {$titles->count()} MDBList refresh jobs.");

        return self::SUCCESS;
    }

    /**
     * Never-checked titles (newest first, so new titles get ratings within the hour), then
     * titles last checked over a week ago (oldest first), up to $limit in total.
     *
     * @return Collection<int, Title>
     */
    private function titlesToRefresh(int $limit): Collection
    {
        $neverChecked = Title::query()
            ->whereNull('ratings_checked_at')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();

        $remaining = $limit - $neverChecked->count();

        if ($remaining <= 0) {
            return $neverChecked;
        }

        $stale = Title::query()
            ->where('ratings_checked_at', '<', Carbon::now()->subDays(self::STALE_AFTER_DAYS))
            ->orderBy('ratings_checked_at')
            ->limit($remaining)
            ->get();

        return $neverChecked->concat($stale)->values();
    }
}
