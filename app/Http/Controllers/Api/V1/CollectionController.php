<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Collection\AddCollectionItem;
use App\Actions\Collection\RemoveCollectionItem;
use App\Actions\Collection\UpdateCollectionItem;
use App\Enums\CollectionFormat;
use App\Enums\TitleType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Collection\CollectionItemRequest;
use App\Http\Resources\V1\CollectionItemResource;
use App\Jobs\ImportCollectionCsv;
use App\Models\CollectionItem;
use App\Models\Title;
use App\Services\Collection\Ownership;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class CollectionController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = CollectionItem::query()
            ->with(['title', 'season']);

        if ($request->filled('format')) {
            $format = CollectionFormat::tryFrom($request->string('format')->toString());
            if ($format !== null) {
                $query->where('format', $format);
            }
        }

        if ($request->filled('type')) {
            $type = TitleType::tryFrom($request->string('type')->toString());
            if ($type !== null) {
                $query->whereHas('title', fn ($q) => $q->where('type', $type));
            }
        }

        if ($request->boolean('loaned')) {
            $query->whereNotNull('loaned_to');
        }

        if ($request->filled('q')) {
            $search = $request->string('q')->toString();
            $query->whereHas('title', fn ($q) => $q->where('name', 'like', "%{$search}%"));
        }

        $items = $query->latest()->paginate(20);

        return CollectionItemResource::collection($items);
    }

    public function showForTitle(Title $title, Ownership $ownership): JsonResponse
    {
        $summary = $ownership->forTitle($title);

        $summary->copies->load(['title', 'season']);

        return response()->json([
            'copies' => CollectionItemResource::collection($summary->copies),
            'in_plex' => $summary->inPlex,
            'is_owned' => $summary->isOwned(),
            'formats' => collect($summary->formats())->map(fn ($format) => [
                'value' => $format->value,
                'label' => $format->label(),
            ]),
            'label' => $summary->label(),
        ]);
    }

    public function store(Title $title, CollectionItemRequest $request, AddCollectionItem $addItem): JsonResponse
    {
        $attributes = $request->validated();

        if (isset($attributes['season_id'])) {
            $season = $title->seasons()->findOrFail($attributes['season_id']);
            $attributes['season_id'] = $season->id;
        }

        $item = $addItem->handle($title, $attributes);
        $item->load(['title', 'season']);

        return response()->json(new CollectionItemResource($item), 201);
    }

    public function update(CollectionItem $item, CollectionItemRequest $request, UpdateCollectionItem $updateItem): CollectionItemResource
    {
        $attributes = $request->validated();

        if (isset($attributes['season_id'])) {
            $season = $item->title->seasons()->findOrFail($attributes['season_id']);
            $attributes['season_id'] = $season->id;
        }

        $item = $updateItem->handle($item, $attributes);
        $item->load(['title', 'season']);

        return new CollectionItemResource($item);
    }

    public function destroy(CollectionItem $item, RemoveCollectionItem $removeItem): JsonResponse
    {
        $removeItem->handle($item);

        return response()->json(null, 204);
    }

    public function import(Request $request): JsonResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'extensions:csv', 'mimes:csv,text/csv,text/plain', 'max:10240'],
        ]);

        $path = $request->file('file')->store('imports', 'local');

        ImportCollectionCsv::dispatch($path);

        return response()->json([
            'message' => 'Import started',
        ], 202);
    }
}
