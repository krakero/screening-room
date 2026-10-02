<?php

namespace App\Http\Controllers\Api\V1\Follows;

use App\Actions\Follows\FollowShow;
use App\Actions\Follows\PauseShow;
use App\Actions\Follows\RestartShow;
use App\Actions\Follows\ResumeShow;
use App\Actions\Follows\StopRewatch;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\FollowResource;
use App\Models\Follow;
use App\Models\Title;
use Illuminate\Http\JsonResponse;

class FollowController extends Controller
{
    public function store(Title $title, FollowShow $followShow): JsonResponse
    {
        $follow = $followShow->handle($title);

        return response()->json(['follow' => new FollowResource($follow)], 201);
    }

    public function pause(Follow $follow, PauseShow $pauseShow): JsonResponse
    {
        return response()->json(['follow' => new FollowResource($pauseShow->handle($follow))]);
    }

    public function resume(Follow $follow, ResumeShow $resumeShow): JsonResponse
    {
        return response()->json(['follow' => new FollowResource($resumeShow->handle($follow))]);
    }

    public function restart(Title $title, RestartShow $restartShow): JsonResponse
    {
        return response()->json(['follow' => new FollowResource($restartShow->handle($title))]);
    }

    public function stopRewatch(Title $title, StopRewatch $stopRewatch): JsonResponse
    {
        $follow = $stopRewatch->handle($title);

        return response()->json(['follow' => $follow ? new FollowResource($follow) : null]);
    }
}
