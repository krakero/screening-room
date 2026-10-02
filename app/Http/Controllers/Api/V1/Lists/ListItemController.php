<?php

namespace App\Http\Controllers\Api\V1\Lists;

use App\Enums\ListReleaseFilter;
use App\Enums\ListSort;
use App\Enums\ShowStatus;
use App\Enums\TitleType;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\MediaListItemResource;
use App\Models\MediaList;
use App\Models\Title;
use App\Services\Lists\ListItemQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

class ListItemController extends Controller
{
    public function index(Request $request, MediaList $list, ListItemQuery $listItemQuery): AnonymousResourceCollection
    {
        $validated = $request->validate([
            'sort' => ['nullable', Rule::enum(ListSort::class)],
            'type' => ['nullable', Rule::enum(TitleType::class)],
            'status' => ['nullable', Rule::enum(ShowStatus::class)],
            'unwatched' => ['nullable', 'boolean'],
            'release' => ['nullable', Rule::enum(ListReleaseFilter::class)],
            'service' => ['nullable', 'integer', 'exists:watch_providers,id'],
            'network' => ['nullable', 'integer', 'exists:networks,id'],
        ]);

        $items = $listItemQuery->forInput(
            $list,
            $validated['sort'] ?? null,
            $validated['type'] ?? null,
            $validated['status'] ?? null,
            $request->boolean('unwatched'),
            $validated['release'] ?? null,
            isset($validated['service']) ? (int) $validated['service'] : null,
            isset($validated['network']) ? (int) $validated['network'] : null,
        );

        return MediaListItemResource::collection($items);
    }

    public function store(Request $request, MediaList $list): JsonResponse
    {
        $validated = $request->validate([
            'title_id' => ['required', 'integer', 'exists:titles,id'],
        ]);

        if ($list->items()->where('title_id', $validated['title_id'])->exists()) {
            return response()->json(['message' => __('This title is already in the list.')], 409);
        }

        $position = ($list->items()->max('position') ?? 0) + 1;

        $list->items()->create([
            'title_id' => $validated['title_id'],
            'position' => $position,
        ]);

        return response()->json(status: 201);
    }

    public function destroy(MediaList $list, Title $title): Response
    {
        $list->items()->where('title_id', $title->id)->get()->each->delete();

        return response()->noContent();
    }

    public function updatePosition(Request $request, MediaList $list, Title $title): Response
    {
        $validated = $request->validate([
            'position' => ['required', 'integer', 'min:0'],
        ]);

        $items = $list->items()->orderBy('position')->get();

        $moving = $items->firstWhere('title_id', $title->id);

        abort_if($moving === null, 404);

        $ordered = $items->reject(fn ($item) => $item->id === $moving->id)->values();
        $ordered->splice(min($validated['position'], $ordered->count()), 0, [$moving]);

        foreach ($ordered->values() as $index => $item) {
            if ($item->position !== $index) {
                $item->update(['position' => $index]);
            }
        }

        return response()->noContent();
    }
}
