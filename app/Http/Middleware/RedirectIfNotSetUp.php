<?php

namespace App\Http\Middleware;

use App\Support\SetupProgress;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Locks the whole app behind the setup wizard until it's finished ("installed"), WordPress-style:
 * everything except the wizard itself, webhooks, health checks, and Livewire's own endpoints
 * redirects to wherever the wizard should resume. Once installed, /setup/* redirects to the
 * dashboard instead — Settings is where changes happen afterwards.
 */
class RedirectIfNotSetUp
{
    public function __construct(private readonly SetupProgress $progress) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->is('webhooks/*', 'up', 'livewire-*/*', 'login', 'logout', '_boost/*')) {
            return $next($request);
        }

        $onSetupRoute = $request->is('setup', 'setup/*');

        if ($this->progress->installed()) {
            return $onSetupRoute ? redirect()->route('dashboard') : $next($request);
        }

        if ($onSetupRoute) {
            return $next($request);
        }

        return redirect()->route('setup.'.$this->progress->currentStep());
    }
}
