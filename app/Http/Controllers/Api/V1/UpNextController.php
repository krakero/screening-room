<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\V1\EpisodeSummaryResource;
use App\Http\Resources\V1\TitleSummaryResource;
use App\Models\Episode;
use App\Services\UpNext\UpNextCache;
use App\Support\Greeting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class UpNextController extends Controller
{
    public function index(Request $request, UpNextCache $upNextCache): JsonResponse
    {
        $airingThisWeek = $upNextCache->airingThisWeekByDay()
            ->map(fn (Collection $episodes): array => $episodes
                ->map(fn (Episode $episode): array => [
                    ...(new EpisodeSummaryResource($episode))->resolve($request),
                    'can_mark_watched' => $episode->hasAired(),
                ])
                ->values()
                ->all());

        return response()->json([
            'greeting' => Greeting::current(),
            'continue_watching' => $upNextCache->continueWatching()
                ->map(fn (array $entry): array => [
                    'title' => (new TitleSummaryResource($entry['title']))->resolve($request),
                    'next_episode' => (new EpisodeSummaryResource($entry['progress']->nextEpisode))->resolve($request),
                ])
                ->values(),
            'airing_this_week' => $airingThisWeek->isEmpty() ? new \stdClass : $airingThisWeek,
            'recently_added' => $upNextCache->recentlyAdded()
                ->map(fn (array $entry): array => [
                    ...(new TitleSummaryResource($entry['title']))->resolve($request),
                    'plex_available' => true,
                    'added_at' => $entry['added_at']->toIso8601String(),
                    'episode_count' => $entry['episode_count'],
                    'season_number' => $entry['season_number'],
                    'episode_number' => $entry['episode_number'],
                    'label' => $entry['label'],
                ])
                ->values(),
        ]);
    }
}
