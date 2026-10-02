@props([
    'label',
    'configured' => false,
])

<flux:input
    {{ $attributes }}
    type="password"
    viewable
    :label="$label"
    :placeholder="$configured ? __('Saved — leave blank to keep it') : __('Not set')"
/>
