<?php

namespace App\Livewire\Concerns;

use Closure;
use Illuminate\Support\Facades\Cache;
use Livewire\Attributes\Renderless;

/**
 * The "render now, resolve the slow/external part in the background" pattern used across the
 * app: a page mounts and renders immediately from what's already cached/stored, dispatches a
 * queued job for anything missing or stale, shows a skeleton for that piece, and polls until the
 * job lands (capped, so a stuck queue never polls forever). Originally hand-rolled per feature
 * (trailer, Plex availability); this trait is the shared shape so new deferred pieces don't
 * reinvent it.
 *
 * Components using this trait must implement `deferredIsResolved(string $key): bool`.
 */
trait HasDeferredLoad
{
    /** @var array<string, int> deferred key => unix timestamp when it started waiting */
    public array $deferredPendingSince = [];

    /**
     * Mark $key pending and dispatch its refresh job, at most once per $lockKey across
     * concurrent requests — a short cache lock guards the page-load race; the job itself should
     * still be `ShouldBeUnique` for longer-term queue-level dedupe. Call only when the caller has
     * already decided a refresh is actually needed (e.g. missing or stale).
     */
    protected function dispatchDeferred(string $key, string $lockKey, Closure $dispatch): void
    {
        $this->deferredPendingSince[$key] = now()->timestamp;

        Cache::lock("deferred-load:{$lockKey}", 10)->get($dispatch);
    }

    /**
     * Mark $key pending without dispatching anything — for payloads where the dispatch already
     * happens as a side effect elsewhere (e.g. inside a service method the view reads).
     */
    protected function markDeferredPending(string $key): void
    {
        $this->deferredPendingSince[$key] = now()->timestamp;
    }

    /**
     * Public so Blade views (`$this->deferredPending('key')`) can gate their skeleton on it.
     */
    public function deferredPending(string $key): bool
    {
        return array_key_exists($key, $this->deferredPendingSince);
    }

    /**
     * How long a deferred key polls before giving up regardless of whether it resolved.
     */
    protected function deferredCapSeconds(): int
    {
        return 30;
    }

    /**
     * The `wire:poll.3s` target. Cheap on every tick — only once $key resolves (per
     * `deferredIsResolved()`) or the cap is hit does it clear the pending state and force a real
     * re-render, matching the original trailer/Plex polling behaviour.
     */
    #[Renderless]
    public function pollDeferred(string $key): void
    {
        if (! $this->deferredPending($key)) {
            return;
        }

        $dispatchedAt = $this->deferredPendingSince[$key];
        $timedOut = now()->timestamp - $dispatchedAt >= $this->deferredCapSeconds();

        if (! $this->deferredIsResolved($key) && ! $timedOut) {
            return;
        }

        unset($this->deferredPendingSince[$key]);
        $this->onDeferredResolved($key);
        $this->forceRender();
    }

    /**
     * Return true once the payload for $key is ready (e.g. by re-checking the cached row the
     * job writes to).
     */
    abstract protected function deferredIsResolved(string $key): bool;

    /**
     * Optional hook run once a deferred key resolves or times out, before the forced re-render —
     * e.g. to refresh a model or clear a #[Computed] cache so it re-reads fresh data.
     */
    protected function onDeferredResolved(string $key): void
    {
        //
    }
}
