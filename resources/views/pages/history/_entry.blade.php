@php
    /** @var \App\Models\Play $play */
    $playable = $play->playable;
    $isEpisode = $playable instanceof \App\Models\Episode;
    $show = $isEpisode ? $playable->title : null;

    $image = $isEpisode
        ? ($playable->stillUrl('w300') ?? $show?->backdropUrl('w300') ?? $show?->posterUrl('w185'))
        : ($playable?->backdropUrl('w300') ?? $playable?->posterUrl('w185'));

    $poster = $isEpisode ? $show?->posterUrl('w185') : $playable?->posterUrl('w185');

    $name = $isEpisode ? ($show?->name ?? __('Deleted title')) : ($playable?->name ?? __('Deleted title'));
    $code = $isEpisode ? sprintf('S%02dE%02d', $playable->season_number, $playable->episode_number) : null;
    $episodeName = $isEpisode ? $playable->name : null;
    $meta = ! $isEpisode ? ($playable?->release_date?->format('Y') ?? __('Movie')) : null;

    $episodeId = $isEpisode ? $playable?->id : null;

    $href = $episodeId !== null
        ? request()->fullUrlWithQuery(['episode' => $episodeId])
        : ($isEpisode
            ? ($show ? route('titles.show', $show) : null)
            : ($playable ? route('titles.show', $playable) : null));

    $time = $play->watched_at ? \App\Support\DisplayTimezone::local($play->watched_at)->format('g:ia') : __('Unknown time');

    $plexItem = $playable?->plexItem;

    $seasonHref = $isEpisode && $show
        ? route('titles.seasons.show', [$show, $playable->season_number])
        : null;

    $showHref = $isEpisode
        ? ($show ? route('titles.show', $show) : null)
        : ($playable ? route('titles.show', $playable) : null);

    $removeConfirm = $play->source !== \App\Enums\PlaySource::Manual
        ? __('This play was recorded from :source, not logged manually here. Remove it anyway?', ['source' => $play->source->value])
        : null;
@endphp

<x-media.episode-card
    size="sm"
    :image="$image"
    :poster="$poster"
    :name="$name"
    :code="$code"
    :episode-name="$episodeName"
    :episode-id="$episodeId"
    :meta="$meta"
    :date="$time"
    :source="$play->source->value"
    :href="$href"
    :plex-available="$plexItem?->found() ?? false"
    :plex-url="$plexItem?->playUrl()"
    :season-href="$seasonHref"
    :show-href="$showHref"
    :on-remove="'removePlay('.$play->id.')'"
    :remove-confirm="$removeConfirm"
    :optimistic-remove="true"
    wire:key="play-{{ $play->id }}"
/>
