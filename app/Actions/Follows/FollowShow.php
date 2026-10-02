<?php

namespace App\Actions\Follows;

use App\Enums\FollowState;
use App\Models\Follow;
use App\Models\Title;
use Illuminate\Support\Carbon;

class FollowShow
{
    /**
     * Start (or resume) following a show. Idempotent: a show already watching/completed is left alone.
     */
    public function handle(Title $title): Follow
    {
        $follow = Follow::query()->firstOrCreate(
            ['title_id' => $title->id],
            ['state' => FollowState::Watching, 'state_changed_at' => Carbon::now()],
        );

        if (in_array($follow->state, [FollowState::Abandoned, FollowState::Paused], true)) {
            $follow->update(['state' => FollowState::Watching, 'state_changed_at' => Carbon::now()]);
        }

        return $follow;
    }
}
