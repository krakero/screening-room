@props([
    'episodeId',
    'watched' => false,
    'hasManualPlay' => false,
    'aired' => false,
])

@if ($watched && $hasManualPlay)
    <flux:button size="sm" variant="ghost" icon="check-circle" wire:click="toggleEpisode({{ $episodeId }})">
        {{ __('Watched') }}
    </flux:button>
@elseif ($watched)
    <flux:button
        size="sm"
        variant="ghost"
        icon="check-circle"
        wire:click="toggleEpisode({{ $episodeId }})"
        wire:confirm="{{ __('This episode was watched via Plex/Trakt, not logged manually here. Remove it anyway?') }}"
    >
        {{ __('Watched') }}
    </flux:button>
@elseif ($aired)
    <x-media.watched-split-button
        action="toggleEpisode"
        :id="$episodeId"
        custom-target="episode"
        :label="__('Mark watched')"
    />
@endif
