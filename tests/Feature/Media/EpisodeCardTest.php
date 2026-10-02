<?php

test('a plain card with no actions renders no menu at all', function () {
    $view = $this->blade(
        '<x-media.episode-card name="Severance" />'
    );

    $view->assertDontSee(__('Episode actions'), false);
});

test('mark-watched shows the watched-on submenu and no unmark item', function () {
    $view = $this->blade(
        '<x-media.episode-card name="Severance" :can-mark-watched="true" on-mark-watched="mark(1)" on-watched-release-date="mark(1, \'release_date\')" on-watched-unknown="mark(1, \'unknown\')" on-pick-datetime="pick(1)" />'
    );

    $view->assertSee(__('Mark watched'));
    $view->assertSee(__('Watched on…'));
    $view->assertSee(__('On release date'));
    $view->assertSee(__('Unknown date'));
    $view->assertSee(__('Pick date & time…'));
    $view->assertDontSee(__('Mark unwatched'));
});

test('optimistic-watched wires the whole watched-on submenu through the optimistic helper, not wire:click', function () {
    $view = $this->blade(
        '<x-media.episode-card name="Severance" :can-mark-watched="true" optimistic-watched on-mark-watched="mark(1)" on-watched-release-date="mark(1, \'release_date\')" on-watched-unknown="mark(1, \'unknown\')" on-pick-datetime="pick(1)" />'
    );

    $view->assertSee('x-show="!value" x-on:click="set(true, () => $wire.mark(1, &#039;release_date&#039;))"', false);
    $view->assertSee('x-show="!value" x-on:click="set(true, () => $wire.mark(1, &#039;unknown&#039;))"', false);
    $view->assertDontSee('wire:click="mark(1, \'release_date\')"', false);
    $view->assertDontSee('wire:click="mark(1, \'unknown\')"', false);
});

test('optimistic-watched with a pick-datetime target wires the watched-custom-flip listeners', function () {
    $view = $this->blade(
        '<x-media.episode-card name="Severance" :can-mark-watched="true" optimistic-watched on-mark-watched="mark(1)" on-pick-datetime="pick(1)" on-pick-datetime-target="episode:1" />'
    );

    $view->assertSee('x-on:watched-custom-flip.window="if ($event.detail.target === \'episode:1\') { bulkSet(true) }"', false);
    $view->assertSee('x-on:watched-custom-flip-revert.window="if ($event.detail.target === \'episode:1\') { bulkSet(false) }"', false);
    $view->assertSee('x-show="!value" x-on:click="pick(1)"', false);
});

test('optimistic-watched binds a server-value attribute and a mutation observer to resync after a Livewire morph', function () {
    $view = $this->blade(
        '<x-media.episode-card name="Severance" :can-mark-watched="true" optimistic-watched :watched="true" on-mark-watched="mark(1)" />'
    );

    $view->assertSee('data-optimistic-server-watched="1"', false);
    $view->assertSee('MutationObserver', false);
    $view->assertSee('sync($el.dataset.optimisticServerWatched === \'1\')', false);
});

test('without optimistic-watched, no server-value resync markup is rendered', function () {
    $view = $this->blade(
        '<x-media.episode-card name="Severance" :can-mark-watched="true" on-mark-watched="mark(1)" />'
    );

    $view->assertDontSee('data-optimistic-server-watched', false);
    $view->assertDontSee('MutationObserver', false);
});

test('without optimistic-watched, on-pick-datetime-target adds no listener', function () {
    $view = $this->blade(
        '<x-media.episode-card name="Severance" :can-mark-watched="true" on-mark-watched="mark(1)" on-pick-datetime="pick(1)" on-pick-datetime-target="episode:1" />'
    );

    $view->assertDontSee('watched-custom-flip', false);
});

test('the watched-on submenu only renders items whose handler prop is set', function () {
    $view = $this->blade(
        '<x-media.episode-card name="Severance" :can-mark-watched="true" on-mark-watched="mark(1)" on-watched-release-date="mark(1, \'release_date\')" />'
    );

    $view->assertSee(__('Mark watched'));
    $view->assertSee(__('Watched on…'));
    $view->assertSee(__('On release date'));
    $view->assertDontSee(__('Unknown date'));
    $view->assertDontSee(__('Pick date & time…'));
});

test('the watched-on submenu is omitted entirely when none of its handlers are set', function () {
    $view = $this->blade(
        '<x-media.episode-card name="Severance" :can-mark-watched="true" on-mark-watched="mark(1)" />'
    );

    $view->assertSee(__('Mark watched'));
    $view->assertDontSee(__('Watched on…'));
});

test('an unmark item without a confirm renders no wire:confirm', function () {
    $view = $this->blade(
        '<x-media.episode-card name="Severance" :watched="true" on-unmark-watched="unmark(1)" />'
    );

    $view->assertSee(__('Mark unwatched'));
    $view->assertDontSee('wire:confirm', false);
});

test('an unmark item with a confirm renders wire:confirm', function () {
    $view = $this->blade(
        '<x-media.episode-card name="Severance" :watched="true" on-unmark-watched="unmark(1)" unmark-confirm="Remove it anyway?" />'
    );

    $view->assertSee(__('Mark unwatched'));
    $view->assertSee('wire:confirm="Remove it anyway?"', false);
});

test('muted hides every watch-related menu item but keeps other actions', function () {
    $view = $this->blade(
        '<x-media.episode-card name="Severance" :muted="true" :can-mark-watched="true" on-mark-watched="mark(1)" :episode-id="1" show-href="/show" />'
    );

    $view->assertDontSee(__('Mark watched'));
    $view->assertDontSee(__('Watched on…'));
    $view->assertSee(__('Open episode'));
    $view->assertSee(__('Go to show'));
    $view->assertSee('opacity-60', false);
});

test('a separator only renders between menu groups that both have items', function () {
    // Primary item only (mark watched) + nav items: exactly one separator, before nav.
    $html = (string) $this->blade(
        '<x-media.episode-card name="Severance" :can-mark-watched="true" on-mark-watched="mark(1)" season-href="/season" show-href="/show" />'
    );

    expect(substr_count($html, __('Go to season')))->toBe(1);
    expect(substr_count($html, 'data-flux-menu-separator'))->toBe(1);
});

test('no separator renders when only one menu group has items', function () {
    $html = (string) $this->blade(
        '<x-media.episode-card name="Severance" season-href="/season" show-href="/show" />'
    );

    expect(substr_count($html, 'data-flux-menu-separator'))->toBe(0);
});

test('two separators render between three populated menu groups', function () {
    $html = (string) $this->blade(
        '<x-media.episode-card name="Severance" :can-mark-watched="true" on-mark-watched="mark(1)" season-href="/season" on-remove="remove(1)" />'
    );

    expect(substr_count($html, 'data-flux-menu-separator'))->toBe(2);
});

test('plex availability defaults to a poster-icon corner marker on the poster', function () {
    $view = $this->blade(
        '<x-media.episode-card name="Severance" poster="https://example.test/poster.jpg" :plex-available="true" plex-url="https://plex.example/play" />'
    );

    $view->assertSee(__('Available on Plex'));
    $view->assertSee(__('Watch on Plex'));
    $view->assertDontSee('Play on Plex');
});

test('plex availability falls back to the corner pill when there is no poster', function () {
    $view = $this->blade(
        '<x-media.episode-card name="Severance" :plex-available="true" plex-url="https://plex.example/play" />'
    );

    $view->assertSee(__('Plex'));
    $view->assertDontSee(__('Available on Plex'));
});

test('plexIndicator corner is honored even with a poster', function () {
    $view = $this->blade(
        '<x-media.episode-card name="Severance" poster="https://example.test/poster.jpg" plex-indicator="corner" :plex-available="true" plex-url="https://plex.example/play" />'
    );

    $view->assertSee(__('Plex'));
    $view->assertDontSee(__('Available on Plex'));
});

test('plexIndicator poster-badge shows a visible Plex label on the poster', function () {
    $view = $this->blade(
        '<x-media.episode-card name="Severance" poster="https://example.test/poster.jpg" plex-indicator="poster-badge" :plex-available="true" plex-url="https://plex.example/play" />'
    );

    $view->assertSee(__('Available on Plex'));
    $view->assertSeeInOrder([__('Available on Plex'), __('Plex')]);
});

test('a movie play renders with no episode code and falls back to meta', function () {
    $view = $this->blade(
        '<x-media.episode-card name="Fight Club" meta="1999" date="Watched 8:42pm" />'
    );

    $view->assertSee('Fight Club');
    $view->assertSee('1999');
    $view->assertSee('Watched 8:42pm');
    $view->assertDontSee('S0');
});

test('a source label renders alongside the date line', function () {
    $view = $this->blade(
        '<x-media.episode-card name="Fight Club" meta="1999" date="8:42pm" source="Plex" />'
    );

    $view->assertSee('8:42pm · Plex');
});

test('the card root isolates its internal z-index layers from the carousel', function () {
    // Regression: without `isolate`, the poster's z-20 (and the menu button's z-20) escape the
    // card into the poster-row carousel's stacking context and paint over its unindexed prev/next
    // arrows. `isolate` confines them to the card. See resources/views/components/media/poster-row.blade.php.
    $view = $this->blade(
        '<x-media.episode-card name="Severance" poster="https://example.test/poster.jpg" />'
    );

    $view->assertSee('relative isolate flex min-w-0', false);
});

test('remove from history renders with its own confirm', function () {
    $view = $this->blade(
        '<x-media.episode-card name="Fight Club" meta="1999" on-remove="removePlay(1)" remove-confirm="Remove this play?" />'
    );

    $view->assertSee(__('Remove from history'));
    $view->assertSee('wire:confirm="Remove this play?"', false);
});
