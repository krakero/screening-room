<?php

test('the branded variant renders the Plex chevron, "Watch on Plex" text, and a safe new-tab link', function () {
    $view = $this->blade(
        '<x-media.plex-play-button url="https://app.plex.tv/desktop/#!/server/abc/details?key=%2Flibrary%2Fmetadata%2F1" branded />'
    );

    $view->assertSee(__('Watch on Plex'));
    $view->assertSee('<svg', false);
    $view->assertSee('href="https://app.plex.tv/desktop/#!/server/abc/details?key=%2Flibrary%2Fmetadata%2F1"', false);
    $view->assertSee('target="_blank"', false);
    $view->assertSee('rel="noopener noreferrer"', false);
});

test('the branded variant renders nothing without a url', function () {
    $view = $this->blade(
        '<x-media.plex-play-button :url="null" branded />'
    );

    $view->assertDontSee(__('Watch on Plex'));
    $view->assertDontSee('<svg', false);
});

test('the icon-only and default variants do not render the branded pill', function () {
    $iconOnly = $this->blade(
        '<x-media.plex-play-button url="https://plex.tv/x" icon-only />'
    );
    $iconOnly->assertDontSee('bg-plex', false);

    $default = $this->blade(
        '<x-media.plex-play-button url="https://plex.tv/x" />'
    );
    $default->assertSee(__('Watch on Plex'));
    $default->assertDontSee('bg-plex', false);
});
