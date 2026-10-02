<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\LibraryState;
use App\Http\Controllers\Controller;
use App\Jobs\SubmitTitleRequest;
use App\Models\LibraryStatus;
use App\Models\Title;
use App\Support\IntegrationSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class RequestController extends Controller
{
    public function store(Request $request, Title $title, IntegrationSettings $settings): JsonResponse
    {
        $validated = $request->validate([
            'seasons' => ['sometimes', 'array'],
            'seasons.*' => ['integer'],
        ]);

        if (! $settings->configured('seerr.url', 'seerr.api_key')) {
            throw ValidationException::withMessages([
                'title' => __('Could not send the request. Check the Seerr connection in Settings.'),
            ]);
        }

        $status = LibraryStatus::updateOrCreate(
            ['title_id' => $title->id],
            ['state' => LibraryState::Pending],
        );

        SubmitTitleRequest::dispatch($title, $validated['seasons'] ?? []);

        return response()->json([
            'status' => ['state' => $status->state->value],
        ], 202);
    }

    public function status(Title $title): JsonResponse
    {
        return response()->json([
            'state' => $title->libraryStatus?->state?->value,
            'seerr_status' => $title->libraryStatus?->seerr_status?->value,
        ]);
    }
}
