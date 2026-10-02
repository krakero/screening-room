<?php

namespace App\Http\Controllers\Api\V1\Ratings;

use App\Actions\Ratings\ClearRatingReview;
use App\Actions\Ratings\SetRatingReview;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\RatingResource;
use App\Models\Title;
use App\Support\DisplayTimezone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ReviewController extends Controller
{
    public function update(Request $request, Title $title, SetRatingReview $setRatingReview): JsonResponse
    {
        $validated = $request->validate([
            'review' => ['nullable', 'string', 'max:5000'],
            'spoilers' => ['sometimes', 'boolean'],
            'watched_on' => ['nullable', 'date'],
        ]);

        $review = $validated['review'] ?? null;
        $reviewedAt = $review !== null && ! empty($validated['watched_on'])
            ? DisplayTimezone::parseLocalToUtc($validated['watched_on'])
            : null;

        $rating = $setRatingReview->handle($title, $review, (bool) ($validated['spoilers'] ?? false), $reviewedAt);

        return response()->json([
            'rating' => $rating ? new RatingResource($rating) : null,
        ]);
    }

    public function destroy(Title $title, ClearRatingReview $clearRatingReview): Response
    {
        $clearRatingReview->handle($title);

        return response()->noContent();
    }
}
