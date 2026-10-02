@php
    $layout = auth()->user()?->layout ?? \App\Enums\AppLayout::Sidebar;
@endphp
<x-dynamic-component :component="$layout->component()" :title="$title ?? null" :full-bleed="$fullBleed ?? false">
    {{ $slot }}
</x-dynamic-component>
