<?php

use App\Http\Middleware\EnsureApiIsSetUp;
use App\Http\Middleware\EnsureCollectionEnabled;
use App\Http\Middleware\RedirectIfNotSetUp;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->validateCsrfTokens(except: [
            'webhooks/*',
        ]);

        $middleware->alias([
            'collection.enabled' => EnsureCollectionEnabled::class,
        ]);

        $middleware->web(append: [
            RedirectIfNotSetUp::class,
        ]);

        // Laravel's fixed middleware-priority list runs `auth` (registered there under its
        // contract, Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests, not the concrete
        // Authenticate class) before any non-prioritized middleware regardless of registration
        // order, so without this, an unauthenticated request on a fresh install would hit
        // `auth`'s redirect-to-login before ever reaching the gate below that gets it to
        // /setup/account instead.
        $middleware->prependToPriorityList(
            before: AuthenticatesRequests::class,
            prepend: RedirectIfNotSetUp::class,
        );

        // Same reasoning as above, for the API's setup gate: without this, an unauthenticated
        // API request while the wizard is unfinished would hit `auth:sanctum`'s 401 before ever
        // reaching EnsureApiIsSetUp's 503.
        $middleware->prependToPriorityList(
            before: AuthenticatesRequests::class,
            prepend: EnsureApiIsSetUp::class,
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
