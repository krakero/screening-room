<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Abandon After (days)
    |--------------------------------------------------------------------------
    |
    | A followed show with no play in this many days is automatically moved
    | to the "abandoned" state and drops off Up Next.
    |
    */

    'abandon_after_days' => env('SHOWING_ABANDON_AFTER_DAYS', 180),

    /*
    |--------------------------------------------------------------------------
    | Episode Refresh (days)
    |--------------------------------------------------------------------------
    |
    | A season's episodes are re-imported from TMDB if they were last synced
    | longer ago than this, in addition to whenever they've never been synced.
    |
    */

    'episode_refresh_days' => env('SHOWING_EPISODE_REFRESH_DAYS', 1),

];
