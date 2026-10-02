<?php

namespace App\Support;

use App\Models\User;

/**
 * Tracks how far a fresh install has gotten through the setup wizard, so the gate knows which
 * step to resume at and whether the wizard is finished ("installed").
 */
class SetupProgress
{
    /**
     * Wizard order. "account" and "tmdb" are required and resolved from real state (does a user
     * exist, is TMDB configured); everything after is optional and tracked by the furthest step
     * reached, stored in IntegrationSettings under "setup.step".
     *
     * @var array<int, string>
     */
    public const STEPS = ['account', 'tmdb', 'preferences', 'import', 'plex', 'requests', 'notifications', 'done'];

    public function __construct(private readonly IntegrationSettings $settings) {}

    public function installed(): bool
    {
        return $this->settings->get('setup.finished') === true;
    }

    public function tmdbConfigured(): bool
    {
        return $this->settings->configured('tmdb.token') || filled(config('services.tmdb.token'));
    }

    /**
     * The step an in-progress install should resume at.
     */
    public function currentStep(): string
    {
        if (User::query()->doesntExist()) {
            return 'account';
        }

        if (! $this->tmdbConfigured()) {
            return 'tmdb';
        }

        $stored = $this->settings->get('setup.step');

        return in_array($stored, self::STEPS, true) ? $stored : 'preferences';
    }

    /**
     * Records that the signed-in user has reached a given optional step, so a later visit resumes
     * there instead of restarting. Never moves backward.
     */
    public function markReached(string $step): void
    {
        $index = array_search($step, self::STEPS, true);
        $storedIndex = array_search($this->settings->get('setup.step'), self::STEPS, true);

        if ($index !== false && ($storedIndex === false || $index > $storedIndex)) {
            $this->settings->set('setup.step', $step);
        }
    }
}
