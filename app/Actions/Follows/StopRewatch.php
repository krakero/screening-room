<?php

namespace App\Actions\Follows;

use App\Models\Follow;
use App\Models\Title;

class StopRewatch
{
    public function __construct(private SyncFollowState $syncFollowState) {}

    /**
     * End an in-progress rewatch without finishing it: clears the restart date (so progress goes
     * back to counting every play ever) and recomputes state as usual — Completed if everything
     * has ever been watched, otherwise Watching.
     */
    public function handle(Title $title): ?Follow
    {
        $follow = $title->follow;

        if ($follow === null || ! $follow->isRewatching()) {
            return $follow;
        }

        $follow->update(['rewatch_started_at' => null]);

        return $this->syncFollowState->handle($follow);
    }
}
