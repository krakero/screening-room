{{--
    x-media.watched-status: one solid, continuous status line — never segmented, no chips on
    it — used by the Title page's hero. Show: the follow state folded straight into the label
    text ("Watching · 2 of 10 episodes") rather than a separate badge; movie: "Watched :date" /
    "Not watched" (a binary full/empty bar so the same "status bar" look carries over).

    Props:
    - isShow (required): switches between the two label/value shapes below.
    - followState (show only): an App\Enums\FollowState|null.
    - watchedCount, airedCount (show only): episode counts for the label and the bar's fill.
    - lastWatchedAt (movie only): a Carbon instance (or null) of the most recent play.
--}}
@props([
    'isShow',
    'followState' => null,
    'watchedCount' => 0,
    'airedCount' => 0,
    'lastWatchedAt' => null,
])

@php
    if ($isShow) {
        $followLabel = match ($followState) {
            \App\Enums\FollowState::Watching => __('Watching'),
            \App\Enums\FollowState::Paused => __('Paused'),
            \App\Enums\FollowState::Abandoned => __('Abandoned'),
            \App\Enums\FollowState::Completed => __('Completed'),
            default => __('Not following'),
        };

        $label = collect([
            $followLabel,
            __(':watched of :total episodes', ['watched' => $watchedCount, 'total' => $airedCount]),
        ])->filter()->implode(' · ');

        $value = $watchedCount;
        $max = max($airedCount, 1);
    } else {
        $label = $lastWatchedAt ? __('Watched :date', ['date' => $lastWatchedAt->format('M j')]) : __('Not watched');
        $value = $lastWatchedAt ? 1 : 0;
        $max = 1;
    }
@endphp

<x-media.progress {{ $attributes }} :value="$value" :max="$max" :label="$label" />
