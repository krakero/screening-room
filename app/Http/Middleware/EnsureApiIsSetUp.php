<?php

namespace App\Http\Middleware;

use App\Support\SetupProgress;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The JSON equivalent of RedirectIfNotSetUp for the API: every /api/v1/* route (except the
 * health check, which reports its own "not_configured" status instead) is locked out with a
 * 503 until the setup wizard has finished.
 */
class EnsureApiIsSetUp
{
    public function __construct(private readonly SetupProgress $progress) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->progress->installed()) {
            return new JsonResponse(['message' => 'Application is not set up yet.'], 503);
        }

        return $next($request);
    }
}
