<div class="flex items-center gap-2 px-1 py-1.5 text-start text-sm">
    <flux:avatar :name="auth()->user()->name" :initials="auth()->user()->initials()" />

    <div class="grid flex-1 text-start text-sm leading-tight">
        <flux:heading class="truncate">{{ auth()->user()->name }}</flux:heading>
        <flux:text class="truncate">{{ auth()->user()->email }}</flux:text>
    </div>
</div>

<flux:menu.separator />

<flux:menu.item :href="route('profile.edit')" icon="cog" wire:navigate>
    {{ __('Settings') }}
</flux:menu.item>

<flux:menu.separator />

<div class="px-2 py-1.5" x-data>
    <flux:text size="sm" class="mb-1.5 block text-ink-subtle">{{ __('Appearance') }}</flux:text>

    <flux:radio.group variant="segmented" size="sm" x-model="$flux.appearance">
        <flux:radio value="dark" icon="moon">{{ __('Dark') }}</flux:radio>
        <flux:radio value="light" icon="sun">{{ __('Light') }}</flux:radio>
        <flux:radio value="system" icon="computer-desktop">{{ __('System') }}</flux:radio>
    </flux:radio.group>
</div>

<flux:menu.separator />

<form method="POST" action="{{ route('logout') }}" class="w-full">
    @csrf
    <flux:menu.item
        as="button"
        type="submit"
        icon="arrow-right-start-on-rectangle"
        class="w-full cursor-pointer"
        data-test="logout-button"
    >
        {{ __('Log out') }}
    </flux:menu.item>
</form>
