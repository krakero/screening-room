<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\V1\CalendarEntryResource;
use App\Services\CalendarCache;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

class CalendarController extends Controller
{
    /**
     * The largest range (in days, inclusive) a single request may span.
     */
    private const MAX_RANGE_DAYS = 90;

    public function index(Request $request, CalendarCache $calendarCache): JsonResponse
    {
        $validated = $request->validate([
            'start' => ['required', 'date_format:Y-m-d'],
            'end' => ['required', 'date_format:Y-m-d', 'after_or_equal:start'],
        ]);

        $start = Carbon::parse($validated['start']);
        $end = Carbon::parse($validated['end']);

        if ($start->diffInDays($end) > self::MAX_RANGE_DAYS) {
            throw ValidationException::withMessages([
                'end' => __('The range between start and end may not exceed :days days.', ['days' => self::MAX_RANGE_DAYS]),
            ]);
        }

        return response()->json([
            'data' => CalendarEntryResource::collection($calendarCache->forRange($start, $end)),
        ]);
    }

    public function catchUp(CalendarCache $calendarCache): JsonResponse
    {
        return response()->json([
            'data' => CalendarEntryResource::collection($calendarCache->catchUp()),
        ]);
    }
}
