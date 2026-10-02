<?php

namespace App\Console\Commands;

use App\Enums\FollowState;
use App\Models\Follow;
use App\Services\ShowProgress;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;

class AbandonStaleFollows extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'follows:abandon-stale';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Abandon stale watching follows with an unwatched aired episode, and revive abandoned follows that are caught up';

    /**
     * How many follows to load show progress for at a time.
     */
    private const CHUNK_SIZE = 200;

    public function __construct(private readonly ShowProgress $showProgress)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $abandoned = $this->abandonStaleFollows();
        $revived = $this->reviveCaughtUpFollows();

        $this->info("Abandoned {$abandoned} stale follow(s); revived {$revived} caught-up follow(s).");

        return self::SUCCESS;
    }

    /**
     * Abandons Watching follows that are stale AND still have an unwatched aired episode.
     * A stale follow the user is simply caught up on (waiting for new episodes) stays Watching.
     */
    private function abandonStaleFollows(): int
    {
        $cutoff = Carbon::now()->subDays((int) config('showing.abandon_after_days', 180));
        $count = 0;

        Follow::query()
            ->where('state', FollowState::Watching)
            ->where(function (Builder $query) use ($cutoff): void {
                $query->where('last_played_at', '<', $cutoff)
                    ->orWhere(function (Builder $query) use ($cutoff): void {
                        $query->whereNull('last_played_at')->where('state_changed_at', '<', $cutoff);
                    });
            })
            ->with('title')
            ->chunkById(self::CHUNK_SIZE, function (Collection $follows) use (&$count): void {
                $progressByTitleId = $this->showProgress->forMany($follows->pluck('title'));

                foreach ($follows as $follow) {
                    if ($progressByTitleId->get($follow->title_id)?->nextEpisode === null) {
                        continue; // caught up: no unwatched aired episode, so leave it Watching
                    }

                    $follow->update(['state' => FollowState::Abandoned, 'state_changed_at' => Carbon::now()]);
                    $count++;
                }
            });

        return $count;
    }

    /**
     * Self-heals follows abandoned above (or previously) that are caught up again, e.g. the
     * show aired nothing new during the abandon window and the user has no unwatched episode.
     * Abandoned is only ever set automatically by this command, so reverting it here is safe.
     *
     * Mirrors App\Actions\Follows\SyncFollowState's watching/completed decision: a caught-up
     * follow whose show has finished airing goes to Completed, otherwise back to Watching.
     */
    private function reviveCaughtUpFollows(): int
    {
        $count = 0;

        Follow::query()
            ->where('state', FollowState::Abandoned)
            ->with('title')
            ->chunkById(self::CHUNK_SIZE, function (Collection $follows) use (&$count): void {
                $progressByTitleId = $this->showProgress->forMany($follows->pluck('title'));

                foreach ($follows as $follow) {
                    $progress = $progressByTitleId->get($follow->title_id);

                    if ($progress === null || $progress->nextEpisode !== null) {
                        continue; // still has an unwatched aired episode; stays Abandoned
                    }

                    $follow->update([
                        'state' => $progress->isComplete ? FollowState::Completed : FollowState::Watching,
                        'state_changed_at' => Carbon::now(),
                    ]);
                    $count++;
                }
            });

        return $count;
    }
}
