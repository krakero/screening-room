@props(['name'])

<flux:dropdown position="bottom" align="end" {{ $attributes }}>
    <flux:profile
        :name="$name"
        :initials="auth()->user()->initials()"
        icon:trailing="chevron-down"
        data-test="user-menu-button"
    />

    <flux:menu>
        <x-user-menu.items />
    </flux:menu>
</flux:dropdown>
