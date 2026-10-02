<?php

namespace Tests;

use App\Support\IntegrationSettings;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;
use Laravel\Fortify\Features;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // No test may reach a real external API (TMDB, Plex, MDBList...), even through a queued
        // job that runs inline on the sync queue; tests fake the responses they need.
        Http::preventStrayRequests();

        $this->markInstalled();
    }

    protected function skipUnlessFortifyHas(string $feature, ?string $message = null): void
    {
        if (! Features::enabled($feature)) {
            $this->markTestSkipped($message ?? "Fortify feature [{$feature}] is not enabled.");
        }
    }

    /**
     * Marks the app as having finished the setup wizard, so the RedirectIfNotSetUp gate leaves
     * the rest of the suite alone. On by default; setup-wizard tests opt out via markNotInstalled().
     */
    protected function markInstalled(): void
    {
        app(IntegrationSettings::class)->set('setup.finished', true);
    }

    /**
     * Opts a test back into the real gate behavior (no user, or user but setup unfinished).
     */
    protected function markNotInstalled(): void
    {
        app(IntegrationSettings::class)->forget('setup.finished');
    }
}
