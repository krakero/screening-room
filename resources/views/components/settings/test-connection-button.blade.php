@props([
    'enabled' => false,
    'action',
])

@if ($enabled)
    <flux:button variant="outline" wire:click="{{ $action }}" wire:target="{{ $action }}" :loading="false" wire:loading.attr="disabled">
        <span wire:loading.remove wire:target="{{ $action }}">{{ __('Test connection') }}</span>
        <span wire:loading wire:target="{{ $action }}" class="inline-flex items-center gap-2">
            <flux:icon.loading variant="micro" />
            {{ __('Testing…') }}
        </span>
    </flux:button>
@else
    <flux:button variant="outline" disabled>{{ __('Test connection') }}</flux:button>
@endif
