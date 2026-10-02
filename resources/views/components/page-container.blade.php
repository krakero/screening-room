{{--
    The single shared page-width definition: the horizontal max-width and gutters
    (max-w-7xl, px-6 lg:px-8) every page's content uses, whether it renders inside
    flux:main or a full-bleed hero layout. `data-flux-container` lets a nested
    <flux:main> defer its own horizontal padding to this element (see flux/main.blade.php's
    `[[data-flux-container]_&]:px-0`).
--}}
<div data-flux-container {{ $attributes->class(['mx-auto w-full max-w-7xl px-6 lg:px-8']) }}>
    {{ $slot }}
</div>
