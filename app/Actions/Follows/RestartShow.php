<?php

namespace App\Actions\Follows;

use App\Enums\FollowState;
use App\Models\Follow;
use App\Models\Title;
use Illuminate\Support\Carbon;

class RestartShow
{
    /**
     * Restart a show from any follow state (including not-yet-followed): sets it Watching and
     * marks a new rewatch starting now, so Up Next's next episode becomes S1E1 regardless of
     * how much was already watched.
     */
    public function handle(Title $title): Follow
    {
        $now = Carbon::now();

        $follow = Follow::query()->firstOrNew(['title_id' => $title->id]);

        $follow->fill([
            'state' => FollowState::Watching,
            'state_changed_at' => $now,
            'rewatch_started_at' => $now,
        ]);

        if (! $follow->exists) {
            $follow->rewatch_count = 0;
        }

        $follow->save();

        return $follow;
    }
}
