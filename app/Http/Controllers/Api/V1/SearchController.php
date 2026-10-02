<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\SearchRequest;
use App\Models\Title;
use App\Services\Search\SearchTitles;
use App\Services\Tmdb\TmdbException;
use Illuminate\Http\JsonResponse;

class SearchController extends Controller
{
    public function index(SearchRequest $request, SearchTitles $search): JsonResponse
    {
        $query = trim($request->string('q')->toString());

        try {
            $results = $search->handle($query);
        } catch (TmdbException) {
            return response()->json([
                'message' => __('Search failed. Please try again in a moment.'),
            ], 503);
        }

        return response()->json([
            'data' => $results->map(function (array $result): array {
                /** @var Title|null $title */
                $title = $result['title'];

                return [
                    'tmdb_id' => $result['tmdb_id'],
                    'type' => $result['type']->value,
                    'name' => $result['name'],
                    'year' => $result['year'],
                    'poster_url' => $result['poster'],
                    'title_id' => $title?->id,
                    'watched' => $result['watched'],
                    'status' => $title?->libraryStatus?->state->value,
                    'on_list' => (bool) ($title?->on_list ?? false),
                    'followed' => ((bool) ($title?->is_followed ?? false)) && ! $result['watched'],
                    'href_action' => $title ? 'show' : 'import',
                ];
            })->all(),
        ]);
    }
}
