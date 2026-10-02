<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Plex\ResolvePlexAvailability;
use App\Http\Controllers\Controller;
use App\Jobs\SyncPlexLibrary;
use App\Models\Episode;
use App\Models\Title;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PlexController extends Controller
{
    public function sync(): JsonResponse
    {
        SyncPlexLibrary::dispatch();

        return response()->json(['message' => 'Sync queued.'], 202);
    }

    public function availability(Request $request, ResolvePlexAvailability $resolver): JsonResponse
    {
        $validated = $request->validate([
            'title_id' => ['required_without:episode_id', 'integer'],
            'episode_id' => ['required_without:title_id', 'integer'],
        ]);

        $episode = array_key_exists('episode_id', $validated) ? Episode::findOrFail($validated['episode_id']) : null;

        $item = $episode
            ? $resolver->forEpisode($episode)
            : $resolver->forTitle(Title::findOrFail($validated['title_id']));

        return response()->json([
            'available' => (bool) $item?->found(),
            'play_url' => $item?->playUrl(),
            'pending' => $episode ? $resolver->isPendingForEpisode($episode) : false,
        ]);
    }
}
