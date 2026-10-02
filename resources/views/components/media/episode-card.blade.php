{{--
    Episode card: the one card for every episode/movie-play listing (Calendar, History, Up Next's
    Continue Watching + Airing This Week shelves, and season pages). A 16:9 still with a soft fade,
    the show/movie poster overlapping bottom-left, and a single top-right vertical-dots menu holding
    every action a listing might need instead of scattering them across slots and toggle positions.

    Props:
    - image, name (required): still image and the show/movie name.
    - code (e.g. "S02E05"), episodeName: episode identity. Omit both for movies.
    - meta: fallback subtitle text when code/episodeName are absent (e.g. a movie's release year, or
      "Movie release"/"Season :n premiere" for calendar entries).
    - date: small subtitle line under the title/meta (e.g. air date, or a play's watched time).
    - source: optional short label (e.g. a play's source — Manual/Plex/Trakt) shown alongside date.
    - href: wraps the card body in a link when set, otherwise renders a div. A real href, so
      middle-click/ctrl-click/right-click "open in new tab" still work.
    - episodeId: when set alongside href, a plain left-click on the card is intercepted (no
      wire:navigate, no full page load): it opens the flyout modal client-side instantly
      (`$flux.modal('episode-flyout').show()`, a skeleton renders until data arrives) and
      dispatches a global `open-episode` Livewire event with `{ episodeId }`, which the app-wide
      `<livewire:episode-flyout />` listens for and loads. Modified clicks (ctrl/cmd/shift/middle)
      are left alone. Also adds an "Open episode" menu item.
    - poster: optional show/movie poster thumbnail (80px on grid cards, 60px on size="sm" shelf
      cards), overlapping the still/text boundary at the bottom-left with equal left/bottom insets.
      Purely decorative: clicks pass through to the card. Omit it for a poster-less card (e.g. a
      season page, already on that show).
    - watched: shows a small accent check badge beside the menu button.
    - canMarkWatched, onMarkWatched: "Mark watched" menu item, plus a "Watched on…" submenu
      (onWatchedReleaseDate, onWatchedUnknown: Livewire method/expression; onPickDatetime: a
      client-side Alpine expression, e.g. from App\Support\CustomWatchedAtTrigger — it opens the
      picker instantly, with no server round trip). The submenu only renders items whose handler
      prop is set, and is omitted entirely when none of the three are set, so a page that can't
      handle one (or any) never shows a dead menu item.
    - onPickDatetimeTarget: with optimisticWatched and onPickDatetime, the exact target string
      passed to that CustomWatchedAtTrigger::open() call (e.g. "episode:123", or a bare episode
      id — whatever the host page uses). The host's shared custom-datetime modal dispatches a
      `watched-custom-flip`/`watched-custom-flip-revert` window event with `{ target }` when its
      Save button is clicked/fails, and only the card whose onPickDatetimeTarget matches flips —
      needed because one modal is shared by every card on the page.
    - onUnmarkWatched, unmarkConfirm: when watched is true, "Mark unwatched" menu item, calling the
      given Livewire method/expression. unmarkConfirm, if set, adds a wire:confirm prompt first.
    - onWatchAgain: when watched is true, an additional "Watch again" menu item (above "Mark
      unwatched") calling the given Livewire method/expression — always adds a new play, never
      removes the existing one. Plain wire:click, no optimistic flip (the card is already showing
      watched).
    - plexAvailable, plexUrl: when both are set, shows a Plex marker and a "Watch on Plex" menu item.
    - plexIndicator: where the Plex marker renders — "poster-icon" (default: a small Plex-yellow
      circle notched into the poster's top-right corner), "corner" (a pill in the still's top-left,
      under any chip-slot content), or "poster-badge" (a Plex-yellow band across the poster's bottom
      edge). Cards with no poster always use "corner", regardless of this prop.
    - seasonHref, showHref: "Go to season" / "Go to show" menu items.
    - onRemove, removeConfirm: "Remove from history" menu item (History's own Remove action),
      calling the given Livewire method/expression. removeConfirm, if set, adds a wire:confirm
      prompt first.
    - muted: dims the whole card (e.g. an unaired episode) and hides every watch-related menu item
      (Mark/unmark watched, "Watched on…") — Plex, Open episode, Go to season/show, and Remove stay
      available.
    - size: null (default) fills its container, for use in a grid; "sm" adds a fixed shelf width
      (shrink-0 snap-start) for use inside a horizontally scrolling row.
    - optimisticWatched: opt-in for the "Mark watched" item — flips the watched badge and hides
      the item instantly (via resources/js/optimistic.js), reverting + toasting if the
      `onMarkWatched` call fails. Only wires up the plain "Mark watched" item; leave off for cards
      that only use unmark/"Watched on…", which stay a plain wire:click round trip. Also binds a
      `data-optimistic-server-watched` attribute to the server-rendered value and resyncs from it
      on mutation (belt and braces against a Livewire morph reusing this card's Alpine scope for a
      different episode — the real fix is a `wire:key` unique per episode in every list, see
      dashboard.blade.php's Continue Watching card).
    - optimisticRemove: opt-in for `onRemove` — the whole card fades out instantly on click (a
      plain JS `confirm()` first when `removeConfirm` is set), reverting + toasting if the call
      fails.
    - optimisticBulkEvent: with optimisticWatched, an optional window event name — when a parent
      bulk action (e.g. "mark season watched") dispatches it, this card's watched badge flips
      instantly too, no per-card request (the parent action already covers the server call).

    Slots:
    - chip: top-left over the still image — an "Up Next"/"Special"/"Premiere"/"Release" badge or
      chip, or a small group of them.
--}}
@props([
    'image' => null,
    'name',
    'code' => null,
    'episodeName' => null,
    'meta' => null,
    'date' => null,
    'source' => null,
    'href' => null,
    'episodeId' => null,
    'poster' => null,
    'watched' => false,
    'canMarkWatched' => false,
    'onMarkWatched' => null,
    'onUnmarkWatched' => null,
    'unmarkConfirm' => null,
    'onWatchAgain' => null,
    'onWatchedReleaseDate' => null,
    'onWatchedUnknown' => null,
    'onPickDatetime' => null,
    'onPickDatetimeTarget' => null,
    'plexAvailable' => false,
    'plexUrl' => null,
    'plexIndicator' => 'poster-icon',
    'seasonHref' => null,
    'showHref' => null,
    'onRemove' => null,
    'removeConfirm' => null,
    'muted' => false,
    'size' => null,
    'optimisticWatched' => false,
    'optimisticRemove' => false,
    'optimisticBulkEvent' => null,
])

@php
    $tag = $href ? 'a' : 'div';
    $subtitle = trim(collect([$code, $episodeName])->filter()->implode(' · '));
    $dateLine = trim(collect([$date, $source])->filter()->implode(' · '));
    $widthClass = $size === 'sm' ? 'w-72 shrink-0 snap-start sm:w-80' : '';
    $insetClass = $size === 'sm' ? 'left-2 bottom-2' : 'left-3 bottom-3';
    $posterWidthClass = $size === 'sm' ? 'w-15' : 'w-20';
    $textPaddingClass = $size === 'sm' ? 'pl-19' : 'pl-25';
    $pbClass = $size === 'sm' ? 'pb-2' : 'pb-3';

    // A plain left-click opens the flyout in place, instantly (the modal shows client-side
    // before the open-episode Livewire request — which loads the episode — comes back; see
    // EpisodeFlyout). Modified clicks (new tab/window, middle-click) fall through to the real
    // href untouched.
    $openEpisodeClick = $episodeId !== null
        ? sprintf(
            "if (!(\$event.metaKey || \$event.ctrlKey || \$event.shiftKey || \$event.button === 1)) { \$event.preventDefault(); \$flux.modal('episode-flyout').show(); \$dispatch('open-episode', { episodeId: %d }) }",
            $episodeId,
        )
        : null;

    $showWatchItems = ! $muted && ($canMarkWatched || ($watched && ($onUnmarkWatched || $onWatchAgain)));
    $hasPrimaryMenuItems = $showWatchItems || ($plexAvailable && $plexUrl) || $episodeId;
    $hasNavItems = $seasonHref || $showHref;
    $hasMenu = $hasPrimaryMenuItems || $hasNavItems || $onRemove;

    $posterPlexIndicator = $poster && $plexAvailable && in_array($plexIndicator, ['poster-icon', 'poster-badge'], true)
        ? $plexIndicator
        : null;
    $showCornerPlexPill = $plexAvailable && $posterPlexIndicator === null;
    $plexIconSizeClass = $size === 'sm' ? 'size-[18px]' : 'size-5';
    $plexIconGlyphClass = $size === 'sm' ? 'size-2.5' : 'size-3';

    // Optimistic UI (resources/js/optimistic.js): a card only ever opts into one of these, so a
    // single `value` on the root x-data covers both — "watched" (starts false, flips true) or
    // "removed" (always starts false, flips true to fade the card out). See docblock above.
    $useOptimisticWatched = $optimisticWatched && $canMarkWatched;
    $useOptimisticRemove = $optimisticRemove && $onRemove;
    $optimisticInitial = $useOptimisticRemove ? false : $watched;
    $removeConfirmJs = $removeConfirm ? 'if (!confirm('.\Illuminate\Support\Js::from($removeConfirm).')) { return } ' : '';
@endphp

<div
    {{ $attributes->class(['group relative isolate flex min-w-0 flex-col overflow-hidden rounded-lg bg-surface', $widthClass, 'opacity-60' => $muted]) }}
    @if ($useOptimisticWatched || $useOptimisticRemove)
        x-data="optimistic(@js($optimisticInitial))"
    @endif
    @if ($useOptimisticWatched)
        data-optimistic-server-watched="{{ $optimisticInitial ? '1' : '0' }}"
        x-init="new MutationObserver(() => sync($el.dataset.optimisticServerWatched === '1')).observe($el, { attributes: true, attributeFilter: ['data-optimistic-server-watched'] })"
    @endif
    @if ($useOptimisticWatched && $optimisticBulkEvent)
        x-on:{{ $optimisticBulkEvent }}.window="bulkSet(true)"
    @endif
    @if ($useOptimisticWatched && $onPickDatetimeTarget !== null)
        x-on:watched-custom-flip.window="if ($event.detail.target === @js($onPickDatetimeTarget)) { bulkSet(true) }"
        x-on:watched-custom-flip-revert.window="if ($event.detail.target === @js($onPickDatetimeTarget)) { bulkSet(false) }"
    @endif
    @if ($useOptimisticRemove)
        x-show="!value"
        x-transition:leave="transition ease-in duration-200"
        x-transition:leave-start="opacity-100"
        x-transition:leave-end="opacity-0"
    @endif
>
    <{{ $tag }}
        @if ($href) href="{{ $href }}" @endif
        @if ($openEpisodeClick)
            x-data
            x-on:click="{{ $openEpisodeClick }}"
        @elseif ($href)
            wire:navigate
        @endif
        class="flex min-w-0 flex-col focus-visible:outline-none"
    >
        <div class="relative aspect-video w-full shrink-0 overflow-hidden bg-surface-raised">
            @if ($image)
                <img src="{{ $image }}" alt="" loading="lazy" class="size-full object-cover" />
            @else
                <span class="flex size-full items-center justify-center">
                    <flux:icon.tv class="size-6 text-ink-subtle" />
                </span>
            @endif

            @if (isset($chip) || $showCornerPlexPill)
                <div class="absolute left-1.5 top-1.5 z-10 flex flex-col items-start gap-1">
                    {{ $chip ?? '' }}

                    @if ($showCornerPlexPill)
                        <x-media.badge tone="plex" variant="solid" icon="play">{{ __('Plex') }}</x-media.badge>
                    @endif
                </div>
            @endif

            <div class="pointer-events-none absolute inset-x-0 bottom-0 h-full bg-gradient-to-t from-surface via-surface/60 via-40% to-transparent"></div>
        </div>

        <div class="relative -mt-9 flex min-w-0 flex-col gap-0.5 pr-2 {{ $poster ? $textPaddingClass : 'px-2' }} {{ $poster ? $pbClass : 'pb-2' }}">
            <span class="truncate text-sm font-medium text-ink" title="{{ $name }}">{{ $name }}</span>

            @if ($subtitle !== '')
                <span class="line-clamp-2 text-xs text-ink-muted" title="{{ $subtitle }}">{{ $subtitle }}</span>
            @elseif ($meta)
                <span class="truncate text-xs text-ink-muted" title="{{ $meta }}">{{ $meta }}</span>
            @endif

            @if ($dateLine !== '')
                <span class="truncate text-xs text-ink-subtle">{{ $dateLine }}</span>
            @endif
        </div>
    </{{ $tag }}>

    <div class="absolute right-1.5 top-1.5 z-20 flex items-center gap-1.5">
        @if ($useOptimisticWatched)
            <span
                x-show="value"
                class="flex size-6 items-center justify-center rounded-full bg-accent text-accent-foreground"
                aria-label="{{ __('Watched') }}"
                title="{{ __('Watched') }}"
            >
                <flux:icon.check class="size-3.5" />
            </span>
        @elseif ($watched)
            <span
                class="flex size-6 items-center justify-center rounded-full bg-accent text-accent-foreground"
                aria-label="{{ __('Watched') }}"
                title="{{ __('Watched') }}"
            >
                <flux:icon.check class="size-3.5" />
            </span>
        @endif

        @if ($hasMenu)
            {{-- The menu items render on first hover/focus/press (x-if below), so a page of many cards
                 doesn't build and initialise a full Flux menu per card up front. --}}
            <flux:dropdown
                position="bottom"
                align="end"
                x-data="{ menuReady: false }"
                x-on:pointerenter.once="menuReady = true"
                x-on:focusin.once="menuReady = true"
                x-on:pointerdown.capture.once="menuReady = true"
                x-on:keydown.capture.once="menuReady = true"
            >
                <flux:button
                    size="sm"
                    variant="ghost"
                    icon="ellipsis-vertical"
                    aria-label="{{ __('Episode actions') }}"
                    title="{{ __('Episode actions') }}"
                    class="!size-9 !rounded-full !bg-canvas/70 text-ink-muted !ring-1 !ring-line backdrop-blur hover:!text-accent hover:!ring-accent"
                />

                <flux:menu>
                    <template x-if="menuReady">
                    <div class="contents">
                    @if ($showWatchItems)
                        @if ($canMarkWatched)
                            @if ($useOptimisticWatched)
                                <flux:menu.item icon="check" x-show="!value" x-on:click="set(true, () => $wire.{{ $onMarkWatched }})">{{ __('Mark watched') }}</flux:menu.item>
                            @else
                                <flux:menu.item icon="check" wire:click="{{ $onMarkWatched }}">{{ __('Mark watched') }}</flux:menu.item>
                            @endif

                            @if ($onWatchedReleaseDate || $onWatchedUnknown || $onPickDatetime)
                                <flux:menu.submenu icon="clock" heading="{{ __('Watched on…') }}">
                                    @if ($onWatchedReleaseDate)
                                        @if ($useOptimisticWatched)
                                            <flux:menu.item x-show="!value" x-on:click="set(true, () => $wire.{{ $onWatchedReleaseDate }})">{{ __('On release date') }}</flux:menu.item>
                                        @else
                                            <flux:menu.item wire:click="{{ $onWatchedReleaseDate }}">{{ __('On release date') }}</flux:menu.item>
                                        @endif
                                    @endif

                                    @if ($onWatchedUnknown)
                                        @if ($useOptimisticWatched)
                                            <flux:menu.item x-show="!value" x-on:click="set(true, () => $wire.{{ $onWatchedUnknown }})">{{ __('Unknown date') }}</flux:menu.item>
                                        @else
                                            <flux:menu.item wire:click="{{ $onWatchedUnknown }}">{{ __('Unknown date') }}</flux:menu.item>
                                        @endif
                                    @endif

                                    @if ($onPickDatetime)
                                        @if ($useOptimisticWatched)
                                            <flux:menu.item x-show="!value" x-on:click="{{ $onPickDatetime }}">{{ __('Pick date & time…') }}</flux:menu.item>
                                        @else
                                            <flux:menu.item x-on:click="{{ $onPickDatetime }}">{{ __('Pick date & time…') }}</flux:menu.item>
                                        @endif
                                    @endif
                                </flux:menu.submenu>
                            @endif
                        @elseif ($watched)
                            @if ($onWatchAgain)
                                <flux:menu.item icon="arrow-path-rounded-square" wire:click="{{ $onWatchAgain }}">
                                    {{ __('Watch again') }}
                                </flux:menu.item>
                            @endif

                            @if ($onUnmarkWatched)
                                @if ($unmarkConfirm)
                                    <flux:menu.item icon="x-mark" wire:click="{{ $onUnmarkWatched }}" wire:confirm="{{ $unmarkConfirm }}">
                                        {{ __('Mark unwatched') }}
                                    </flux:menu.item>
                                @else
                                    <flux:menu.item icon="x-mark" wire:click="{{ $onUnmarkWatched }}">
                                        {{ __('Mark unwatched') }}
                                    </flux:menu.item>
                                @endif
                            @endif
                        @endif
                    @endif

                    @if ($plexAvailable && $plexUrl)
                        <flux:menu.item icon="play" :href="$plexUrl" target="_blank" rel="noopener noreferrer">
                            {{ __('Watch on Plex') }}
                        </flux:menu.item>
                    @endif

                    @if ($episodeId)
                        <flux:menu.item icon="rectangle-stack" x-on:click="$flux.modal('episode-flyout').show(); $dispatch('open-episode', { episodeId: {{ $episodeId }} })">
                            {{ __('Open episode') }}
                        </flux:menu.item>
                    @endif

                    @if ($hasNavItems)
                        @if ($hasPrimaryMenuItems)
                            <flux:menu.separator />
                        @endif

                        @if ($seasonHref)
                            <flux:menu.item icon="film" :href="$seasonHref" wire:navigate>{{ __('Go to season') }}</flux:menu.item>
                        @endif

                        @if ($showHref)
                            <flux:menu.item icon="tv" :href="$showHref" wire:navigate>{{ __('Go to show') }}</flux:menu.item>
                        @endif
                    @endif

                    @if ($onRemove)
                        @if ($hasPrimaryMenuItems || $hasNavItems)
                            <flux:menu.separator />
                        @endif

                        @if ($useOptimisticRemove)
                            <flux:menu.item icon="trash" variant="danger" x-on:click="{{ $removeConfirmJs }}set(true, () => $wire.{{ $onRemove }})">
                                {{ __('Remove from history') }}
                            </flux:menu.item>
                        @elseif ($removeConfirm)
                            <flux:menu.item icon="trash" variant="danger" wire:click="{{ $onRemove }}" wire:confirm="{{ $removeConfirm }}">
                                {{ __('Remove from history') }}
                            </flux:menu.item>
                        @else
                            <flux:menu.item icon="trash" variant="danger" wire:click="{{ $onRemove }}">
                                {{ __('Remove from history') }}
                            </flux:menu.item>
                        @endif
                    @endif
                    </div>
                    </template>
                </flux:menu>
            </flux:dropdown>
        @endif
    </div>

    @if ($poster)
        <div @class(["pointer-events-none absolute z-20 aspect-[2/3]", $posterWidthClass, $insetClass])>
            <div class="relative size-full overflow-hidden rounded-md shadow-md ring-1 ring-line">
                <img src="{{ $poster }}" alt="" loading="lazy" class="size-full object-cover" />

                @if ($posterPlexIndicator === 'poster-badge')
                    <span class="absolute inset-x-0 bottom-0 bg-plex px-1 py-0.5 text-center text-[9px] font-semibold text-plex-foreground" aria-label="{{ __('Available on Plex') }}" title="{{ __('Available on Plex') }}">
                        {{ __('Plex') }}
                    </span>
                @endif
            </div>

            @if ($posterPlexIndicator === 'poster-icon')
                <span @class(["absolute -right-1.5 -top-1.5 z-10 flex items-center justify-center rounded-full bg-plex text-plex-foreground ring-2 ring-surface", $plexIconSizeClass]) aria-label="{{ __('Available on Plex') }}" title="{{ __('Available on Plex') }}">
                    <flux:icon.play :class="$plexIconGlyphClass" />
                </span>
            @endif
        </div>
    @endif

    <x-media.hover-ring />
</div>
