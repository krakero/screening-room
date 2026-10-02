<?php

namespace App\Support;

/**
 * Builds the client-side Alpine expression behind every "Pick date & time…" menu item: seeds
 * "now" in the user's display timezone (an Intl call in the browser, using the timezone rendered
 * once as `<html data-timezone>`) and opens the given Flux modal — no server round trip. Assumes
 * an ancestor `x-data` exposes `customDatetime` (and `customDatetimeTarget` when $target is given),
 * which the host's Save button then passes back as arguments when it calls its confirm action.
 */
final class CustomWatchedAtTrigger
{
    public static function open(string $modal, ?string $target = null): string
    {
        $setTarget = $target !== null ? "customDatetimeTarget = '{$target}'; " : '';

        return $setTarget.
            'customDatetime = window.nowInDisplayTimezone(document.documentElement.dataset.timezone); '.
            "\$flux.modal('{$modal}').show()";
    }
}
