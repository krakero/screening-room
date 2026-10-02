<div
    wire:key="title-lists-{{ $this->memberListIds->sort()->implode('-') }}"
    x-data="optimistic(@js($this->memberListIds->all()))"
>
    {{-- A flux:menu.submenu, not a nested flux:dropdown, so it opens/closes reliably from
    inside the "…" menu's own flux:dropdown (Flux implements submenus as a native <ui-submenu>
    web component rather than a second Livewire-driven dropdown). --}}
    <flux:menu.submenu icon="bookmark" heading="{{ __('Add to List') }}">
        <flux:menu.item x-on:click="set(value.includes({{ $this->watchlist->id }}) ? value.filter((id) => id !== {{ $this->watchlist->id }}) : [...value, {{ $this->watchlist->id }}], () => $wire.toggleWatchlist())">
            <flux:icon.check class="me-2 size-4 shrink-0" x-show="value.includes({{ $this->watchlist->id }})" x-cloak />
            <flux:icon.bookmark class="me-2 size-4 shrink-0" x-show="!value.includes({{ $this->watchlist->id }})" x-cloak />
            {{ __('Watchlist') }}
        </flux:menu.item>

        @if ($this->customLists->isNotEmpty())
            <flux:menu.separator />

            @foreach ($this->customLists as $mediaList)
                <flux:menu.item
                    wire:key="title-lists-item-{{ $mediaList->id }}"
                    x-on:click="set(value.includes({{ $mediaList->id }}) ? value.filter((id) => id !== {{ $mediaList->id }}) : [...value, {{ $mediaList->id }}], () => $wire.toggleList({{ $mediaList->id }}))"
                >
                    <flux:icon.check class="me-2 size-4 shrink-0" x-show="value.includes({{ $mediaList->id }})" x-cloak />
                    <div class="me-2 size-4 shrink-0" x-show="!value.includes({{ $mediaList->id }})"></div>
                    {{ $mediaList->name }}
                </flux:menu.item>
            @endforeach
        @endif

        <flux:menu.separator />

        @if ($creatingList)
            <form wire:submit="createList" class="flex items-center gap-2 px-2 py-1.5">
                <flux:input size="sm" wire:model="newListName" :placeholder="__('New list name')" autofocus />
                <flux:button size="sm" type="submit" variant="primary" icon="check" />
            </form>
        @else
            <flux:menu.item icon="plus" wire:click="$set('creatingList', true)">
                {{ __('New list') }}
            </flux:menu.item>
        @endif
    </flux:menu.submenu>
</div>
