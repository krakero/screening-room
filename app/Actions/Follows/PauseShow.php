<?php

namespace App\Actions\Follows;

use App\Enums\FollowState;
use App\Models\Follow;
use Illuminate\Support\Carbon;

class PauseShow
{
    public function handle(Follow $follow): Follow
    {
        $follow->update([
            'state' => FollowState::Paused,
            'state_changed_at' => Carbon::now(),
        ]);

        return $follow;
    }
}
