@php
    $moreNavItems = collect(\App\Support\PrimaryNavigation::items())
        ->reject(fn (array $item) => in_array($item['route'], ['dashboard', 'discover', 'lists.index'], true));
@endphp

<nav
    class="fixed inset-x-0 bottom-0 z-40 flex items-stretch border-t border-line bg-surface/95 pb-[env(safe-area-inset-bottom)] backdrop-blur md:hidden"
    aria-label="{{ __('Primary') }}"
>
    <a href="{{ route('dashboard') }}" wire:navigate class="flex flex-1 flex-col items-center gap-1 py-2 text-xs {{ request()->routeIs('dashboard') ? 'text-accent-content' : 'text-ink-subtle' }}">
        <flux:icon.home variant="{{ request()->routeIs('dashboard') ? 'solid' : 'outline' }}" class="size-5" />
        {{ __('Up Next') }}
    </a>

    <a href="{{ route('discover') }}" wire:navigate class="flex flex-1 flex-col items-center gap-1 py-2 text-xs {{ request()->routeIs('discover') ? 'text-accent-content' : 'text-ink-subtle' }}">
        <flux:icon.sparkles variant="{{ request()->routeIs('discover') ? 'solid' : 'outline' }}" class="size-5" />
        {{ __('Discover') }}
    </a>

    <a href="{{ route('search') }}" wire:navigate class="flex flex-1 flex-col items-center gap-1 py-2 text-xs {{ request()->routeIs('search') ? 'text-accent-content' : 'text-ink-subtle' }}">
        <flux:icon.magnifying-glass variant="{{ request()->routeIs('search') ? 'solid' : 'outline' }}" class="size-5" />
        {{ __('Search') }}
    </a>

    <a href="{{ route('lists.index') }}" wire:navigate class="flex flex-1 flex-col items-center gap-1 py-2 text-xs {{ request()->routeIs('lists.*') ? 'text-accent-content' : 'text-ink-subtle' }}">
        <flux:icon.queue-list variant="{{ request()->routeIs('lists.*') ? 'solid' : 'outline' }}" class="size-5" />
        {{ __('Lists') }}
    </a>

    <flux:dropdown position="top" align="end" class="flex flex-1">
        <button type="button" class="flex flex-1 flex-col items-center gap-1 py-2 text-xs text-ink-subtle">
            <flux:icon.ellipsis-horizontal class="size-5" />
            {{ __('More') }}
        </button>

        <flux:menu>
            @foreach ($moreNavItems as $item)
                <flux:menu.item :href="route($item['route'])" :icon="$item['icon']" wire:navigate>
                    {{ $item['label'] }}
                </flux:menu.item>
            @endforeach

            <flux:menu.separator />

            <x-user-menu.items />
        </flux:menu>
    </flux:dropdown>
</nav>
