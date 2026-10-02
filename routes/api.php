<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\CalendarController;
use App\Http\Controllers\Api\V1\CollectionController;
use App\Http\Controllers\Api\V1\DiscoverController;
use App\Http\Controllers\Api\V1\DownloadController;
use App\Http\Controllers\Api\V1\EpisodeController;
use App\Http\Controllers\Api\V1\Follows\FollowController;
use App\Http\Controllers\Api\V1\Lists\ListController;
use App\Http\Controllers\Api\V1\Lists\ListItemController;
use App\Http\Controllers\Api\V1\Lists\TitleListController;
use App\Http\Controllers\Api\V1\MeController;
use App\Http\Controllers\Api\V1\PlayController;
use App\Http\Controllers\Api\V1\PlexController;
use App\Http\Controllers\Api\V1\Ratings\RatingController;
use App\Http\Controllers\Api\V1\Ratings\ReviewController;
use App\Http\Controllers\Api\V1\RequestController;
use App\Http\Controllers\Api\V1\SearchController;
use App\Http\Controllers\Api\V1\SeasonController;
use App\Http\Controllers\Api\V1\SettingsController;
use App\Http\Controllers\Api\V1\Stats\StatsController;
use App\Http\Controllers\Api\V1\TitleController;
use App\Http\Controllers\Api\V1\UpNextController;
use App\Http\Middleware\EnsureApiIsSetUp;
use App\Support\SetupProgress;
use Illuminate\Support\Facades\Route;

Route::get('v1', function () {
    return response()->json([
        'status' => app(SetupProgress::class)->installed() ? 'ok' : 'not_configured',
        'version' => 'v1',
    ]);
});

Route::prefix('v1')->middleware(EnsureApiIsSetUp::class)->group(function () {
    Route::post('auth/token', [AuthController::class, 'store'])->middleware('throttle:5,1');
    Route::post('auth/plex', [AuthController::class, 'plex'])->middleware('throttle:5,1');

    Route::middleware('auth:sanctum')->group(function () {
        Route::delete('auth/token', [AuthController::class, 'destroy']);
        Route::get('me', [MeController::class, 'show']);
        Route::put('me', [MeController::class, 'update']);

        Route::get('settings/integrations', [SettingsController::class, 'integrations']);

        Route::post('plex/sync', [PlexController::class, 'sync']);
        Route::get('plex/availability', [PlexController::class, 'availability']);

        Route::post('titles/{title}/request', [RequestController::class, 'store']);
        Route::get('titles/{title}/request-status', [RequestController::class, 'status']);

        // A5: Lists / Stats
        Route::get('lists', [ListController::class, 'index']);
        Route::post('lists', [ListController::class, 'store']);
        Route::put('lists/{list}', [ListController::class, 'update']);
        Route::delete('lists/{list}', [ListController::class, 'destroy']);
        Route::get('lists/{list}/items', [ListItemController::class, 'index']);
        Route::post('lists/{list}/items', [ListItemController::class, 'store']);
        Route::delete('lists/{list}/items/{title}', [ListItemController::class, 'destroy']);
        Route::put('lists/{list}/items/{title}/position', [ListItemController::class, 'updatePosition']);

        Route::get('titles/{title}/lists', [TitleListController::class, 'show']);
        Route::post('titles/{title}/lists/{list}/toggle', [TitleListController::class, 'toggle']);

        Route::get('stats', [StatsController::class, 'summary']);
        Route::get('stats/years', [StatsController::class, 'years']);

        // A3: Watch actions / Plays / History
        Route::post('episodes/{episode}/watch', [PlayController::class, 'watchEpisode']);
        Route::delete('episodes/{episode}/watch', [PlayController::class, 'unwatchEpisode']);
        Route::delete('plays/{play}', [PlayController::class, 'destroy']);
        Route::post('titles/{title}/watch', [PlayController::class, 'watchMovie']);
        Route::post('titles/{title}/watch-show', [PlayController::class, 'watchShow']);
        Route::get('titles/{title}/watch-show/preview', [PlayController::class, 'previewWatchShow']);
        Route::post('seasons/{season}/watch', [PlayController::class, 'watchSeason']);
        Route::get('seasons/{season}/watch/preview', [PlayController::class, 'previewWatchSeason']);
        Route::get('history', [PlayController::class, 'history']);

        // A2: Up Next / Calendar
        Route::get('up-next', [UpNextController::class, 'index']);
        Route::get('calendar', [CalendarController::class, 'index']);
        Route::get('calendar/catch-up', [CalendarController::class, 'catchUp']);

        // A4: Ratings / Reviews / Follows
        Route::get('titles/{title}/rating', [RatingController::class, 'show']);
        Route::put('titles/{title}/rating', [RatingController::class, 'update']);
        Route::delete('titles/{title}/rating', [RatingController::class, 'destroy']);
        Route::put('titles/{title}/rating/review', [ReviewController::class, 'update']);
        Route::delete('titles/{title}/rating/review', [ReviewController::class, 'destroy']);

        Route::post('titles/{title}/follow', [FollowController::class, 'store']);
        Route::post('follows/{follow}/pause', [FollowController::class, 'pause']);
        Route::post('follows/{follow}/resume', [FollowController::class, 'resume']);
        Route::post('titles/{title}/restart', [FollowController::class, 'restart']);
        Route::post('titles/{title}/stop-rewatch', [FollowController::class, 'stopRewatch']);

        // A1: Titles / Seasons / Episodes / Import / Search / Discover
        Route::post('titles/import', [TitleController::class, 'import']);
        Route::get('titles/{title}', [TitleController::class, 'show']);
        Route::post('titles/{title}/refresh', [TitleController::class, 'refresh']);
        Route::get('titles/{title}/seasons/{seasonNumber}', [SeasonController::class, 'show']);
        Route::get('episodes/{episode}', [EpisodeController::class, 'show']);
        Route::get('search', [SearchController::class, 'index']);
        Route::get('downloads', [DownloadController::class, 'index']);
        Route::get('discover/trending', [DiscoverController::class, 'trending']);
        Route::get('discover/new', [DiscoverController::class, 'new']);
        Route::get('discover/new-episodes', [DiscoverController::class, 'newEpisodes']);
        Route::get('discover/recommendations', [DiscoverController::class, 'recommendations']);

        // Collection API
        Route::middleware('collection.enabled')->group(function () {
            Route::get('collection', [CollectionController::class, 'index']);
            Route::get('titles/{title}/collection', [CollectionController::class, 'showForTitle']);
            Route::post('titles/{title}/collection', [CollectionController::class, 'store']);
            Route::put('collection/{item}', [CollectionController::class, 'update']);
            Route::delete('collection/{item}', [CollectionController::class, 'destroy']);
            Route::post('collection/import', [CollectionController::class, 'import']);
        });
    });
});
