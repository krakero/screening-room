@props([])

<div {{ $attributes->class(['grid grid-cols-2 gap-4 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-6']) }}>
    {{ $slot }}
</div>
