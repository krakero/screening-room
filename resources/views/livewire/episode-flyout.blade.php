@php
    $episode = $this->episode;
@endphp

<div x-data="{ customDatetime: '' }">
<flux:modal
    name="episode-flyout"
    variant="flyout"
    class="w-full overflow-y-auto bg-canvas! border-line! md:max-w-md [&::backdrop]:bg-canvas/50! [&::backdrop]:backdrop-blur-[2px]!"
    @close="close"
>
    {{-- Shown the instant the card's Alpine click opens this modal client-side, before the
         open/goTo request (which loads the episode) has come back. --}}
    <div wire:loading.flex wire:target="open, goTo" class="flex flex-col gap-6">
        <div class="-m-8 mb-0 aspect-video w-[calc(100%+4rem)] shrink-0 animate-pulse bg-surface-raised"></div>

        <div class="flex flex-col gap-3">
            <div class="h-3 w-24 animate-pulse rounded bg-surface-raised"></div>
            <div class="h-6 w-2/3 animate-pulse rounded bg-surface-raised"></div>
            <div class="h-3 w-full animate-pulse rounded bg-surface-raised"></div>
            <div class="h-3 w-4/5 animate-pulse rounded bg-surface-raised"></div>
        </div>
    </div>

    @if ($episode)
        @php
            $watched = \App\Support\WatchedSince::watched($episode->plays, $this->progressSince);
            $hasManualPlay = $episode->plays->contains(fn ($play) => $play->source === \App\Enums\PlaySource::Manual);
            $code = sprintf('S%02dE%02d', $episode->season_number, $episode->episode_number);
        @endphp

        <div wire:loading.remove wire:target="open, goTo" class="-m-8 mb-0 aspect-video w-[calc(100%+4rem)] shrink-0 overflow-hidden bg-surface-raised">
            <div class="relative size-full">
                @if ($episode->stillUrl('w780'))
                    <img src="{{ $episode->stillUrl('w780') }}" alt="" class="size-full object-cover" />
                @elseif ($episode->title->backdropUrl('w780'))
                    <img src="{{ $episode->title->backdropUrl('w780') }}" alt="" class="size-full object-cover" />
                @else
                    <span class="flex size-full items-center justify-center">
                        <flux:icon.tv class="size-10 text-ink-subtle" />
                    </span>
                @endif

                <div class="absolute inset-0 bg-gradient-to-t from-canvas via-canvas/60 to-transparent"></div>
                <div class="absolute inset-0 bg-gradient-to-r from-canvas/50 via-transparent to-transparent"></div>
            </div>
        </div>

        <div wire:loading.remove wire:target="open, goTo" class="flex flex-col gap-6">
            <div class="relative z-10 -mt-10 flex flex-col gap-1">
                <a href="{{ route('titles.show', $episode->title) }}" wire:navigate class="text-sm font-medium text-ink-muted hover:text-ink">
                    {{ $episode->title->name }}
                </a>

                <flux:heading size="lg">
                    <a href="{{ route('titles.seasons.show', [$episode->title, $episode->season_number]) }}" wire:navigate class="text-accent-content hover:underline">{{ $code }}</a>{{ $episode->name ? ' — '.$episode->name : '' }}
                </flux:heading>

                <div class="flex flex-wrap items-center gap-2 text-xs text-ink-subtle">
                    @if ($episode->air_date)
                        <span>{{ $episode->air_date->format('M j, Y') }}</span>
                    @endif

                    @if ($episode->runtime)
                        <span>&middot;</span>
                        <span>{{ __(':minutes min', ['minutes' => $episode->runtime]) }}</span>
                    @endif

                </div>
            </div>

            @if ($episode->overview)
                <p class="text-sm leading-relaxed text-ink-muted">{{ $episode->overview }}</p>
            @endif

            @if ($episode->hasAired())
                <div class="flex flex-wrap items-center gap-2">
                    {{-- flex-wrap lets the branded Plex button drop to its own line on narrow widths
                         instead of squeezing the watched controls or overflowing. --}}
                    {{-- Instant checkmark flip on click; reverts + shows a danger toast if the server call
                         fails (resources/js/optimistic.js). `manual` mirrors $hasManualPlay but is tracked
                         client-side too, since marking watched from here always logs a manual play — that
                         keeps the "needs confirm to remove" branch correct even before the real response
                         (which still lands normally, updating the season progress bar etc.) arrives. --}}
                    <div
                        x-data="{ ...optimistic(@js($watched)), manual: @js($hasManualPlay) }"
                        x-on:episode-flyout-datetime-picked.window="manual = true; bulkSet(true)"
                        x-on:episode-flyout-datetime-failed.window="bulkSet(false)"
                    >
                        <template x-if="value && manual">
                            <flux:button.group>
                                <flux:button size="sm" variant="filled" icon="check-circle" x-on:click="set(false, () => $wire.toggleWatched())">
                                    {{ __('Watched') }}
                                </flux:button>

                                <flux:dropdown position="bottom" align="start">
                                    <flux:button size="sm" variant="filled" icon="chevron-down" aria-label="{{ __('More watched options') }}" />

                                    <flux:menu>
                                        <flux:menu.item wire:click="watchAgain">{{ __('Watch again') }}</flux:menu.item>
                                    </flux:menu>
                                </flux:dropdown>
                            </flux:button.group>
                        </template>

                        <template x-if="value && !manual">
                            <flux:button.group>
                                <flux:button
                                    size="sm"
                                    variant="filled"
                                    icon="check-circle"
                                    x-on:click="if (confirm('{{ __('This episode was watched via Plex/Trakt, not logged manually here. Remove it anyway?') }}')) { set(false, () => $wire.toggleWatched()) }"
                                >
                                    {{ __('Watched') }}
                                </flux:button>

                                <flux:dropdown position="bottom" align="start">
                                    <flux:button size="sm" variant="filled" icon="chevron-down" aria-label="{{ __('More watched options') }}" />

                                    <flux:menu>
                                        <flux:menu.item wire:click="watchAgain">{{ __('Watch again') }}</flux:menu.item>
                                    </flux:menu>
                                </flux:dropdown>
                            </flux:button.group>
                        </template>

                        <template x-if="!value">
                            <flux:button.group>
                                <flux:button size="sm" variant="filled" icon="check" x-on:click="manual = true; set(true, () => $wire.toggleWatched())">
                                    {{ __('Mark watched') }}
                                </flux:button>

                                <flux:dropdown position="bottom" align="start">
                                    <flux:button size="sm" variant="filled" icon="chevron-down" aria-label="{{ __('More watched options') }}" />

                                    <flux:menu>
                                        <flux:menu.item x-on:click="manual = true; set(true, () => $wire.toggleWatched('release_date'))">{{ __('On release date') }}</flux:menu.item>
                                        <flux:menu.item x-on:click="manual = true; set(true, () => $wire.toggleWatched('unknown'))">{{ __('Unknown date') }}</flux:menu.item>
                                        <flux:menu.item x-on:click="{{ \App\Support\CustomWatchedAtTrigger::open('episode-flyout-custom-watched-at') }}">{{ __('Pick date & time…') }}</flux:menu.item>
                                    </flux:menu>
                                </flux:dropdown>
                            </flux:button.group>
                        </template>
                    </div>

                    @if ($awaitingPlexDispatchedAt !== null)
                        <flux:button size="sm" variant="ghost" icon="arrow-path" icon:class="animate-spin" disabled wire:poll.3s="checkPlex" :aria-label="__('Checking Plex…')" :title="__('Checking Plex…')" />
                    @else
                        <x-media.plex-play-button :url="$plexUrl" branded />
                    @endif
                </div>

                @if ($this->plays->isNotEmpty())
                    <div class="flex flex-col gap-1.5">
                        <span class="text-xs font-medium uppercase tracking-wide text-ink-subtle">{{ __('Plays') }}</span>

                        @foreach ($this->plays as $play)
                            <div class="flex items-center justify-between gap-2 text-sm text-ink-muted" wire:key="play-{{ $play->id }}">
                                <span>{{ $play->watched_at ? \App\Support\DisplayTimezone::local($play->watched_at)->format('M j, Y g:ia') : __('Unknown date') }}</span>
                                <x-media.chip>{{ $play->source->value }}</x-media.chip>
                            </div>
                        @endforeach
                    </div>
                @endif

            @else
                <x-media.chip>{{ __('Unaired') }}</x-media.chip>
            @endif

            <div class="flex items-center justify-between gap-4 border-t border-line pt-4">
                <flux:button
                    size="sm"
                    variant="ghost"
                    icon="chevron-left"
                    :disabled="! $this->previousEpisode"
                    wire:click="{{ $this->previousEpisode ? 'goTo('.$this->previousEpisode->id.')' : '' }}"
                >
                    {{ __('Previous') }}
                </flux:button>

                <flux:button
                    size="sm"
                    variant="ghost"
                    icon:trailing="chevron-right"
                    :disabled="! $this->nextEpisode"
                    wire:click="{{ $this->nextEpisode ? 'goTo('.$this->nextEpisode->id.')' : '' }}"
                >
                    {{ __('Next') }}
                </flux:button>
            </div>

            <div class="flex flex-col gap-3">
                <a
                    href="{{ route('titles.seasons.show', [$episode->title, $episode->season_number]) }}"
                    wire:navigate
                    class="group flex items-center justify-between gap-3 rounded-lg border border-line bg-surface p-3 transition duration-200 hover:ring-2 hover:ring-accent focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent"
                >
                    <div class="flex flex-col gap-1">
                        <span class="text-sm font-medium text-ink">{{ __('Season :number', ['number' => $episode->season_number]) }} &middot; {{ __(':count episodes', ['count' => $this->seasonStats['total']]) }}</span>
                        <x-media.progress :value="$this->seasonStats['watched']" :max="$this->seasonStats['total']" :label="__(':watched / :total watched', ['watched' => $this->seasonStats['watched'], 'total' => $this->seasonStats['total']])" class="w-40" />
                    </div>

                    <flux:icon.chevron-right class="size-4 shrink-0 text-ink-subtle transition group-hover:text-accent" />
                </a>

                <a href="{{ route('titles.show', $episode->title) }}" wire:navigate class="group flex w-20 flex-col gap-1.5 focus-visible:outline-none sm:w-24">
                    <div class="relative aspect-2/3 w-full overflow-hidden rounded-lg bg-surface-raised">
                        @if ($episode->title->posterUrl())
                            <img src="{{ $episode->title->posterUrl() }}" alt="" class="size-full object-cover" />
                        @else
                            <span class="flex size-full items-center justify-center">
                                <flux:icon.tv class="size-6 text-ink-subtle" />
                            </span>
                        @endif

                        <x-media.hover-ring />
                    </div>

                    <span class="truncate text-center text-xs text-ink-muted">{{ $episode->title->name }}</span>
                </a>
            </div>
        </div>
    @endif
</flux:modal>

<flux:modal name="episode-flyout-custom-watched-at" class="md:w-96">
    <form
        x-on:submit.prevent="
            $flux.modal('episode-flyout-custom-watched-at').close();
            window.dispatchEvent(new CustomEvent('episode-flyout-datetime-picked'));
            $wire.confirmCustomDatetime(customDatetime).catch(() => {
                window.dispatchEvent(new CustomEvent('episode-flyout-datetime-failed'));
                window.dispatchEvent(new CustomEvent('toast-show', { detail: { variant: 'danger', text: @js(__('That didn’t save — please try again.')) } }));
            });
        "
        class="space-y-6"
    >
        <div>
            <flux:heading size="lg">{{ __('Pick a date & time') }}</flux:heading>
        </div>

        <flux:input type="datetime-local" x-model="customDatetime" name="customDatetime" :label="__('Watched at')" />

        <div class="flex justify-end gap-2">
            <flux:modal.close>
                <flux:button variant="filled">{{ __('Cancel') }}</flux:button>
            </flux:modal.close>

            <flux:button type="submit" variant="primary">{{ __('Save') }}</flux:button>
        </div>
    </form>
</flux:modal>
</div>
