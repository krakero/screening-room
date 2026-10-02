<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Discover\DiscoverFeed;
use App\Services\Discover\DiscoverItem;
use App\Services\Discover\DiscoverShelf;
use App\Services\Tmdb\TmdbException;
use Illuminate\Http\JsonResponse;

class DiscoverController extends Controller
{
    public function trending(DiscoverFeed $feed): JsonResponse
    {
        return $this->itemsResponse(fn () => $feed->trending());
    }

    public function new(DiscoverFeed $feed): JsonResponse
    {
        return $this->itemsResponse(fn () => $feed->inTheatersAndComingSoon());
    }

    public function newEpisodes(DiscoverFeed $feed): JsonResponse
    {
        return $this->itemsResponse(fn () => $feed->newEpisodesThisWeek());
    }

    public function recommendations(DiscoverFeed $feed): JsonResponse
    {
        try {
            $shelves = $feed->becauseYouWatched();
        } catch (TmdbException) {
            return response()->json([
                'message' => __('Could not load recommendations right now.'),
            ], 503);
        }

        return response()->json([
            'data' => collect($shelves)->map(fn (DiscoverShelf $shelf): array => [
                'heading' => $shelf->heading,
                'items' => $this->serialize($shelf->items),
            ])->all(),
        ]);
    }

    /**
     * @param  callable(): array<int, DiscoverItem>  $loader
     */
    private function itemsResponse(callable $loader): JsonResponse
    {
        try {
            $items = $loader();
        } catch (TmdbException) {
            return response()->json([
                'message' => __('Could not load titles right now.'),
            ], 503);
        }

        return response()->json(['data' => $this->serialize($items)]);
    }

    /**
     * @param  array<int, DiscoverItem>  $items
     * @return array<int, array<string, mixed>>
     */
    private function serialize(array $items): array
    {
        return collect($items)->map(fn (DiscoverItem $item): array => [
            'tmdb_id' => $item->tmdbId,
            'type' => $item->type->value,
            'name' => $item->name,
            'year' => $item->year,
            'poster_url' => $item->poster,
            'title_id' => $item->title?->id,
            'watched' => $item->watched,
            'status' => $item->status,
            'on_list' => $item->onList,
            'followed' => $item->followed,
            'is_re_release' => $item->isReRelease,
        ])->all();
    }
}
