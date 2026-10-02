<?php

namespace App\Http\Controllers\Api\V1\Lists;

use App\Http\Controllers\Controller;
use App\Models\MediaList;
use App\Models\Title;
use Illuminate\Http\JsonResponse;

class TitleListController extends Controller
{
    public function show(Title $title): JsonResponse
    {
        $watchlist = MediaList::watchlist();
        $memberListIds = $title->mediaLists()->pluck('media_lists.id');

        $customLists = MediaList::where('is_watchlist', false)->orderBy('name')->get();

        return response()->json([
            'watchlist' => [
                'id' => $watchlist->id,
                'in_list' => $memberListIds->contains($watchlist->id),
            ],
            'custom_lists' => $customLists->map(fn (MediaList $mediaList): array => [
                'id' => $mediaList->id,
                'name' => $mediaList->name,
                'in_list' => $memberListIds->contains($mediaList->id),
            ])->values()->all(),
        ]);
    }

    public function toggle(Title $title, MediaList $list): JsonResponse
    {
        $inList = $title->mediaLists()->where('media_lists.id', $list->id)->exists();

        if ($inList) {
            $list->items()->where('title_id', $title->id)->get()->each->delete();
        } else {
            $position = ($list->items()->max('position') ?? 0) + 1;
            $list->items()->create(['title_id' => $title->id, 'position' => $position]);
        }

        return response()->json(['in_list' => ! $inList]);
    }
}
