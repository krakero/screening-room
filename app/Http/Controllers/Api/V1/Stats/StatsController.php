<?php

namespace App\Http\Controllers\Api\V1\Stats;

use App\Http\Controllers\Controller;
use App\Services\Stats\StatsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StatsController extends Controller
{
    public function __construct(private readonly StatsService $stats) {}

    public function summary(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'year' => ['sometimes', 'integer', 'digits:4'],
        ]);

        return response()->json($this->stats->summary($validated['year'] ?? null));
    }

    public function years(): JsonResponse
    {
        return response()->json(['data' => $this->stats->availableYears()]);
    }
}
