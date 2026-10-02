<div
    wire:key="watchlist-toggle-{{ $this->onWatchlist ? 'on' : 'off' }}"
    x-data="optimistic(@js($this->onWatchlist))"
>
    <flux:button
        size="sm"
        variant="filled"
        x-bind:class="value && 'bg-accent! text-accent-foreground!'"
        x-bind:aria-pressed="value"
        x-on:click="set(! value, () => $wire.toggle())"
    >
        <flux:icon.bookmark variant="solid" class="size-4 shrink-0" x-show="value" x-cloak />
        <flux:icon.bookmark class="size-4 shrink-0" x-show="! value" x-cloak />
        <span x-text="value ? @js(__('On Watchlist')) : @js(__('Watchlist'))"></span>
    </flux:button>
</div>
