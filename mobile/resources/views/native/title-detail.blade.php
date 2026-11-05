@if($loading)
    <native:column class="w-full h-full items-center justify-center bg-theme-background">
        <native:activity-indicator size="lg" />
        <native:text class="mt-4 text-theme-on-surface-variant">Loading...</native:text>
    </native:column>
@elseif($error)
    <native:column class="w-full h-full items-center justify-center p-6 gap-4 bg-theme-background">
        <native:text class="text-center text-theme-on-surface-variant">{{ $error }}</native:text>
        <native:button label="Retry" @press="retry" />
    </native:column>
@elseif($title)
    <native:column class="w-full h-full bg-theme-background">
        {{-- Pull-to-refresh scrollable content --}}
        <native:refreshable class="w-full h-full" @refresh="fetchTitle">
            {{-- Backdrop/Poster Header --}}
            @if(!empty($title['backdrop_url']))
                <native:column class="w-full">
                    <native:image
                        src="{{ $title['backdrop_url_w780'] ?? $title['backdrop_url'] }}"
                        :width="0"
                        :height="200"
                        :fit="3"
                    />
                </native:column>
            @endif

            <native:column class="w-full p-4 gap-4">
                {{-- Title & Year --}}
                <native:text class="text-2xl font-bold text-theme-on-background">{{ $title['name'] }}</native:text>

                @if(!empty($title['year_range']))
                    <native:text class="text-theme-on-surface-variant">{{ $title['year_range'] }}</native:text>
                @endif

                {{-- Metadata line: chips don't wrap in a native row, so one muted line reads better --}}
                @php
                    $seasonCount = count($title['seasons'] ?? []);
                    $meta = array_filter([
                        $title['type'] === 'movie' && ! empty($title['runtime']) ? $title['runtime'].' min' : null,
                        $title['type'] === 'show' && $seasonCount > 0 ? $seasonCount.' '.\Illuminate\Support\Str::plural('Season', $seasonCount) : null,
                        $title['type'] === 'show' && ! empty($title['show_status']) ? ucfirst($title['show_status']) : null,
                        ...($title['genres'] ?? []),
                    ]);
                @endphp
                @if(! empty($meta))
                    <native:text class="text-sm text-theme-on-surface-variant">{{ implode(' · ', $meta) }}</native:text>
                @endif

                {{-- Network --}}
                @if(!empty($title['networks'][0]))
                    <native:row class="gap-2 items-center">
                        @if(!empty($title['networks'][0]['logo_url']))
                            <native:image
                                src="{{ $title['networks'][0]['logo_url'] }}"
                                :width="60"
                                :height="30"
                                :fit="1"
                            />
                        @else
                            <native:text class="text-sm text-theme-on-surface-variant">{{ $title['networks'][0]['name'] }}</native:text>
                        @endif
                    </native:row>
                @endif

                {{-- Ratings --}}
                @if(!empty($title['external_ratings']))
                    <native:row class="gap-3 flex-wrap">
                        @foreach(array_slice($title['external_ratings'], 0, 4) as $rating)
                            <native:column class="gap-1">
                                <native:text class="text-xs text-theme-on-surface-variant uppercase">{{ $rating['source'] }}</native:text>
                                <native:text class="text-sm font-semibold text-theme-on-background">{{ $rating['formatted'] }}</native:text>
                            </native:column>
                        @endforeach
                    </native:row>
                @endif

                {{-- Overview --}}
                @if(!empty($title['overview']))
                    <native:text class="text-theme-on-background">{{ $title['overview'] }}</native:text>
                @endif

                {{-- Progress for Shows --}}
                @if($title['type'] === 'show' && !empty($title['progress']))
                    <native:column class="gap-2">
                        <native:progress-bar
                            :progress="$title['progress']['percent']"
                        />
                        <native:text class="text-sm text-theme-on-surface-variant">
                            {{ $title['progress']['watched_count'] }} of {{ $title['progress']['aired_count'] }} episodes watched
                        </native:text>
                    </native:column>
                @endif

                {{-- Primary Actions --}}
                <native:column class="gap-2 mt-2">
                    {{-- Plex Badge --}}
                    @if(!empty($title['plex_play_url']))
                        <native:badge label="Available on Plex" variant="success" />
                    @endif

                    <native:row class="gap-2">
                        {{-- Movie: Watch Button --}}
                        @if($title['type'] === 'movie')
                            <native:button
                                label="{{ $this->movieWatched() ? 'Mark Unwatched' : 'Mark Watched' }}"
                                variant="{{ $this->movieWatched() ? 'secondary' : 'primary' }}"
                                @press="toggleMovieWatched"
                            />
                        @endif

                        {{-- Show: Follow Button --}}
                        @if($title['type'] === 'show')
                            <native:button
                                label="{{ $this->followButtonLabel() }}"
                                variant="primary"
                                @press="followAction"
                            />
                        @endif

                        {{-- Request Button --}}
                        @if($this->canRequest())
                            <native:button
                                label="Request"
                                variant="success"
                                @press="requestTitle"
                            />
                        @elseif($title['request_status'])
                            <native:button
                                label="{{ $this->requestButtonLabel() }}"
                                variant="ghost"
                                disabled
                            />
                        @endif
                    </native:row>
                </native:column>

                {{-- Show: Season Picker --}}
                @if($title['type'] === 'show' && !empty($title['seasons']))
                    <native:column class="w-full gap-2 mt-4">
                        <native:text class="text-lg font-semibold text-theme-on-background">Seasons</native:text>

                        <native:row class="gap-2 flex-wrap">
                            @foreach($title['seasons'] as $season)
                                <native:button
                                    label="S{{ $season['season_number'] }}"
                                    variant="{{ $selectedSeasonNumber === $season['season_number'] ? 'primary' : 'secondary' }}"
                                    size="sm"
                                    @press="fetchSeason({{ $season['season_number'] }})"
                                />
                            @endforeach
                        </native:row>
                    </native:column>

                    {{-- Season Details --}}
                    @if($selectedSeason)
                        <native:column class="w-full gap-3 mt-4">
                            <native:row class="justify-between items-center">
                                <native:text class="text-lg font-semibold text-theme-on-background">
                                    {{ $selectedSeason['name'] }}
                                </native:text>

                                @if($selectedSeason['aired_unwatched_count'] > 0)
                                    <native:button
                                        label="Mark Watched"
                                        variant="success"
                                        size="sm"
                                        @press="showMarkSeasonWatchedConfirm"
                                    />
                                @endif
                            </native:row>

                            @if($seasonError)
                                <native:column class="w-full p-3 bg-theme-error-container rounded">
                                    <native:text class="text-theme-on-error-container">{{ $seasonError }}</native:text>
                                    <native:button label="Retry" size="sm" @press="retrySeason" class="mt-2" />
                                </native:column>
                            @endif

                            @if($loadingSeason)
                                <native:column class="w-full items-center justify-center p-6">
                                    <native:activity-indicator />
                                </native:column>
                            @else
                                {{-- Episodes List --}}
                                @foreach($selectedSeason['episodes'] ?? [] as $episode)
                                    @php
                                        $watched = $this->episodeWatched($episode['id'], $episode['watched'] ?? false);
                                        $hasAired = $episode['has_aired'] ?? true;
                                        $opacityClass = $hasAired ? '' : 'opacity-50';
                                    @endphp
                                    <native:pressable
                                        class="w-full p-3 rounded-lg bg-theme-surface"
                                        @press="toggleEpisodeWatched({{ $episode['id'] }})"
                                    >
                                        <native:row class="gap-3 items-center justify-between">
                                            <native:column class="gap-1 flex-1 {{ $opacityClass }}">
                                                <native:text class="font-semibold text-theme-on-surface">
                                                    E{{ $episode['episode_number'] }}: {{ $episode['name'] }}
                                                </native:text>
                                                <native:text class="text-sm text-theme-on-surface-variant">
                                                    {{ $episode['air_date'] ?? 'TBA' }}
                                                </native:text>
                                            </native:column>
                                            <native:icon
                                                name="{{ $watched ? 'checkmark.circle.fill' : 'circle' }}"
                                                :size="24"
                                                class="{{ $watched ? 'text-theme-primary' : 'text-theme-on-surface-variant' }}"
                                            />
                                        </native:row>
                                    </native:pressable>
                                @endforeach

                                @if(empty($selectedSeason['episodes']))
                                    <native:text class="text-center text-theme-on-surface-variant">No episodes</native:text>
                                @endif
                            @endif
                        </native:column>
                    @endif

                    {{-- Mark Season Watched Confirmation --}}
                    @if($showSeasonConfirm)
                        <native:column class="w-full p-4 gap-3 bg-theme-surface-variant rounded">
                            <native:text class="font-semibold text-theme-on-surface-variant">
                                Mark {{ $seasonConfirmCount }} episode(s) as watched?
                            </native:text>
                            <native:row class="gap-2">
                                <native:button
                                    label="Cancel"
                                    variant="secondary"
                                    @press="cancelMarkSeasonWatched"
                                />
                                <native:button
                                    label="Confirm"
                                    variant="primary"
                                    @press="confirmMarkSeasonWatched"
                                />
                            </native:row>
                        </native:column>
                    @endif
                @endif
            </native:column>
        </native:refreshable>
    </native:column>
@else
    <native:column class="w-full h-full items-center justify-center p-6 gap-4 bg-theme-background">
        <native:text class="text-center text-theme-on-surface-variant">Title not found</native:text>
        <native:button label="Go Back" @press.back />
    </native:column>
@endif
