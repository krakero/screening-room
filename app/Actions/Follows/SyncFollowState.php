<?php

namespace App\Actions\Follows;

use App\Enums\FollowState;
use App\Models\Follow;
use App\Services\ShowProgress;
use Illuminate\Support\Carbon;

class SyncFollowState
{
    public function __construct(private ShowProgress $showProgress) {}

    /**
     * Recomputes watching/completed based on show progress. Paused and abandoned
     * follows are left untouched — they only change via an explicit action.
     *
     * While rewatching, ShowProgress already measures progress since the restart date, so
     * "complete" here means complete since the restart: finishing a rewatch clears it, bumps
     * the count, and completes the follow, same as finishing a show for the first time.
     */
    public function handle(Follow $follow): Follow
    {
        if (in_array($follow->state, [FollowState::Paused, FollowState::Abandoned], true)) {
            return $follow;
        }

        $isComplete = $this->showProgress->isComplete($follow->title);

        if ($follow->isRewatching() && $isComplete) {
            $follow->update([
                'state' => FollowState::Completed,
                'state_changed_at' => Carbon::now(),
                'rewatch_started_at' => null,
                'rewatch_count' => $follow->rewatch_count + 1,
            ]);

            return $follow;
        }

        $targetState = $isComplete ? FollowState::Completed : FollowState::Watching;

        if ($follow->state !== $targetState) {
            $follow->update([
                'state' => $targetState,
                'state_changed_at' => Carbon::now(),
            ]);
        }

        return $follow;
    }
}
