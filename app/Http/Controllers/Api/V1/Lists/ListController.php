<?php

namespace App\Http\Controllers\Api\V1\Lists;

use App\Http\Controllers\Controller;
use App\Http\Resources\V1\MediaListResource;
use App\Models\MediaList;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class ListController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        $lists = MediaList::withCount('items')
            ->with(['titles' => fn ($query) => $query
                ->select('titles.id', 'titles.poster_path')
                ->orderBy('media_list_items.position')
                ->limit(5)])
            ->orderByDesc('is_watchlist')
            ->orderBy('name')
            ->get();

        return MediaListResource::collection($lists);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
        ]);

        $mediaList = MediaList::create([
            'name' => $validated['name'],
            'slug' => MediaList::uniqueSlug($validated['name']),
        ]);

        return response()->json(['list' => new MediaListResource($mediaList)], 201);
    }

    public function update(Request $request, MediaList $list): JsonResponse
    {
        abort_if($list->is_watchlist, 403);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
        ]);

        $list->update(['name' => $validated['name']]);

        return response()->json(['list' => new MediaListResource($list)]);
    }

    public function destroy(MediaList $list): Response
    {
        abort_if($list->is_watchlist, 403);

        $list->delete();

        return response()->noContent();
    }
}
