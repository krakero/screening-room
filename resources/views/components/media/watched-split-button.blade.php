@props([
    'action',
    'customTarget',
    'id' => null,
    'label' => __('Mark watched'),
    'size' => null,
    'variant' => 'filled',
    'icon' => 'check',
    'modal' => 'custom-watched-at',
])

@php
    $call = fn (?string $when = null) => $id !== null
        ? "{$action}({$id}".($when ? ", '{$when}'" : '').')'
        : "{$action}(".($when ? "'{$when}'" : '').')';

    $customDatetimeTarget = $id !== null ? "{$customTarget}:{$id}" : $customTarget;
@endphp

{{--
    x-data lives on this plain wrapping div, not on <flux:button.group> below. Flux's group
    component folds/forwards attributes through its own render pass, and putting Alpine state
    directly on it left descendant x-show/x-bind expressions evaluating "pending" out of scope
    ("pending is not defined" in the console, both the normal and loading labels staying visible
    at once). A plain div guarantees a stable Alpine scope for everything inside it.
--}}
<div
    {{ $attributes }}
    x-data="{
        pending: false,
        run(action) {
            this.pending = true;

            action().catch(() => {
                window.dispatchEvent(new CustomEvent('toast-show', {
                    detail: { variant: 'danger', text: @js(__('That didn’t save — please try again.')) },
                }));
            }).finally(() => { this.pending = false });
        },
    }"
>
    <flux:button.group>
        <flux:button
            :size="$size"
            variant="{{ $variant }}"
            icon="{{ $icon }}"
            x-on:click="run(() => $wire.{{ $call() }})"
            x-bind:disabled="pending"
        >
            <span x-show="!pending">{{ $label }}</span>
            <span x-show="pending" x-cloak class="inline-flex items-center gap-2">
                <flux:icon.loading variant="micro" />
                {{ $label }}
            </span>
        </flux:button>

        <flux:dropdown position="bottom" align="end">
            <flux:button :size="$size" variant="{{ $variant }}" icon="chevron-down" aria-label="{{ __('More watched options') }}" x-bind:disabled="pending" />

            <flux:menu>
                <flux:menu.item x-on:click="run(() => $wire.{{ $call('release_date') }})">{{ __('On release date') }}</flux:menu.item>
                <flux:menu.item x-on:click="run(() => $wire.{{ $call('unknown') }})">{{ __('Unknown date') }}</flux:menu.item>
                <flux:menu.item x-on:click="{{ \App\Support\CustomWatchedAtTrigger::open($modal, $customDatetimeTarget) }}">{{ __('Pick date & time…') }}</flux:menu.item>
            </flux:menu>
        </flux:dropdown>
    </flux:button.group>
</div>
