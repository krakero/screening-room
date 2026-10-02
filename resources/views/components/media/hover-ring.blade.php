{{--
    Shared hover/focus ring overlay for media tiles (poster-card, episode-card's inspiration,
    the show-poster tile in the episode flyout). Render as a child of a `relative` (or
    `group relative`) element sized to the tile/image you want ringed — it fills that element
    via `inset-0` and reacts to the nearest ancestor with the `group` class.

    Pass extra classes (e.g. a different `rounded-*`) via the component tag; they merge with
    the defaults below.
--}}
<span
    aria-hidden="true"
    {{ $attributes->class(['media-ring']) }}
></span>
