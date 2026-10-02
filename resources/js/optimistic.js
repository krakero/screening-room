/**
 * Reusable optimistic-UI helper for Livewire actions: flips local state the instant a
 * control is clicked, then reverts it and shows an error toast if the server call fails.
 *
 * Usage in Blade:
 *
 *   <button
 *       x-data="optimistic(@js($inWatchlist))"
 *       x-on:click="set(!value, () => $wire.toggleWatchlist())"
 *       :aria-pressed="value"
 *   >
 *
 * `value` is read for instant visual feedback (icon state, classes, etc.). `set()` takes the
 * new local value plus a callback that returns the `$wire` call's promise. Livewire rejects
 * that promise on any non-2xx response (validation errors, exceptions, 404s), so on failure
 * `value` reverts to what it was before the click and a danger toast fires via the same
 * `toast-show` event Flux's own `Flux::toast()` helper dispatches (see <flux:toast.group> in
 * the app layouts) — no extra toast component needed.
 *
 * `bulkSet(newValue)` is for a parent bulk action (e.g. "mark season watched") that fires a
 * single server call on its own and just needs every affected card to flip instantly alongside
 * it — no per-card action/promise/revert, since the parent action owns that.
 *
 * `sync(newValue)` resets local state to the server-rendered value. Livewire's DOM morph
 * preserves an element's Alpine scope (and thus `value`) when it reuses the same node, which
 * happens if a list item's `wire:key` isn't unique per identity — e.g. a card keyed by show
 * instead of by episode keeps showing the previous episode's optimistic "watched" flip once the
 * card morphs to the next episode. Callers should bind `sync` to a DOM mutation of the
 * server-rendered value (see episode-card.blade.php) as a safety net on top of correct keying,
 * not a substitute for it.
 */
document.addEventListener('alpine:init', () => {
    Alpine.data('optimistic', (initialValue) => ({
        value: initialValue,

        async set(newValue, action) {
            const previous = this.value;
            this.value = newValue;

            try {
                await action();
            } catch (error) {
                this.value = previous;

                window.dispatchEvent(new CustomEvent('toast-show', {
                    detail: {
                        variant: 'danger',
                        text: 'That didn’t save — please try again.',
                    },
                }));
            }
        },

        bulkSet(newValue) {
            this.value = newValue;
        },

        sync(newValue) {
            this.value = newValue;
        },
    }));
});
