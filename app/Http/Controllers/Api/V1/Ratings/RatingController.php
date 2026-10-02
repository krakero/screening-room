<?php

namespace App\Http\Controllers\Api\V1\Ratings;

use App\Actions\Ratings\ClearRatingScore;
use App\Actions\Ratings\SetRatingScore;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\RatingResource;
use App\Models\Title;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RatingController extends Controller
{
    public function show(Title $title): JsonResponse
    {
        $rating = $title->rating()->first();

        return response()->json([
            'rating' => $rating ? new RatingResource($rating) : null,
        ]);
    }

    public function update(Request $request, Title $title, SetRatingScore $setRatingScore, ClearRatingScore $clearRatingScore): JsonResponse
    {
        $validated = $request->validate([
            'score' => ['nullable', 'integer', 'between:1,10'],
        ]);

        $rating = ! empty($validated['score'])
            ? $setRatingScore->handle($title, $validated['score'])
            : $clearRatingScore->handle($title);

        return response()->json([
            'rating' => $rating ? new RatingResource($rating) : null,
        ]);
    }

    public function destroy(Title $title, ClearRatingScore $clearRatingScore): Response
    {
        $clearRatingScore->handle($title);

        return response()->noContent();
    }
}
