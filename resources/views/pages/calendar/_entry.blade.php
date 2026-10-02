@php
    /** @var \App\Services\CalendarEntry $entry */
    $isEpisode = $entry->type === \App\Enums\CalendarEntryType::Episode;
    $isPremiere = $entry->type === \App\Enums\CalendarEntryType::SeasonPremiere;
    $isMovie = $entry->type === \App\Enums\CalendarEntryType::MovieRelease;

    $image = $isMovie
        ? ($entry->title->backdropUrl('w300') ?? $entry->title->posterUrl('w185'))
        : ($entry->episode?->stillUrl('w300') ?? $entry->title->backdropUrl('w300') ?? $entry->title->posterUrl('w185'));

    $code = $entry->episode ? sprintf('S%02dE%02d', $entry->episode->season_number, $entry->episode->episode_number) : null;

    $episodeName = $isEpisode ? $entry->episode?->name : null;

    $meta = match (true) {
        $isMovie => __('Movie release'),
        $isPremiere => __('Season :number premiere', ['number' => $entry->episode?->season_number ?? 1]),
        default => $entry->title->name,
    };

    $canMarkWatched = $isEpisode && ! $entry->watched && $entry->episode?->hasAired();

    $onWatchedReleaseDate = $entry->episode ? sprintf("markWatched(%d, 'release_date')", $entry->episode->id) : null;
    $onWatchedUnknown = $entry->episode ? sprintf("markWatched(%d, 'unknown')", $entry->episode->id) : null;
    $onPickDatetime = $entry->episode ? \App\Support\CustomWatchedAtTrigger::open('custom-watched-at', (string) $entry->episode->id) : null;

    $episodeId = $entry->episode?->id;
    $cardHref = $episodeId !== null
        ? request()->fullUrlWithQuery(['episode' => $episodeId])
        : route('titles.show', $entry->title);

    $plexItem = $isMovie ? $entry->title->plexItem : $entry->episode?->plexItem;
    $seasonHref = $entry->episode
        ? route('titles.seasons.show', [$entry->title, $entry->episode->season_number])
        : null;
@endphp

<x-media.episode-card
    :image="$image"
    :poster="$entry->title->posterUrl('w185')"
    :name="$entry->title->name"
    :code="$code"
    :episode-name="$episodeName"
    :episode-id="$episodeId"
    :meta="$meta"
    :date="$entry->date->format('D, M j')"
    :href="$cardHref"
    :watched="$entry->watched"
    :can-mark-watched="$canMarkWatched"
    :on-mark-watched="$entry->episode ? 'markWatched('.$entry->episode->id.')' : null"
    :on-watched-release-date="$onWatchedReleaseDate"
    :on-watched-unknown="$onWatchedUnknown"
    :on-pick-datetime="$onPickDatetime"
    :on-pick-datetime-target="$entry->episode ? (string) $entry->episode->id : null"
    :optimistic-watched="true"
    :plex-available="$plexItem?->found() ?? false"
    :plex-url="$plexItem?->playUrl()"
    :season-href="$seasonHref"
    :show-href="route('titles.show', $entry->title)"
    wire:key="calendar-entry-{{ $entry->type->value }}-{{ $entry->episode?->id ?? $entry->title->id }}"
>
    @if ($isPremiere)
        <x-slot:chip>
            <x-media.chip>{{ __('Premiere') }}</x-media.chip>
        </x-slot:chip>
    @elseif ($isMovie)
        <x-slot:chip>
            <x-media.chip>{{ __('Release') }}</x-media.chip>
        </x-slot:chip>
    @endif
</x-media.episode-card>
