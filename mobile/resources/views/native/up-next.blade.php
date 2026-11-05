@if($loading)
    <native:column class="w-full h-full items-center justify-center bg-theme-background">
        <native:activity-indicator />
        <native:text class="mt-4 text-theme-on-surface-variant">Loading...</native:text>
    </native:column>
@elseif($error)
    <native:column class="w-full h-full items-center justify-center p-6 gap-4 bg-theme-background">
        <native:text class="text-center text-theme-on-surface-variant">{{ $error }}</native:text>
        <native:button label="Retry" @press="retry" variant="primary" />
    </native:column>
@elseif(empty($continueWatching) && empty($airingThisWeek) && empty($recentlyAdded))
    <native:column class="w-full h-full items-center justify-center p-6 bg-theme-background">
        <native:text class="text-xl font-bold text-theme-on-background">Nothing to watch yet</native:text>
        <native:text class="mt-2 text-center text-theme-on-surface-variant">Start following shows to see what's up next</native:text>
    </native:column>
@else
    <native:column class="w-full h-full bg-theme-background">
        <native:refreshable class="w-full h-full" @refresh="fetchData">
            {{-- Greeting --}}
            @if($greeting)
                <native:column class="px-4 pt-4 pb-2">
                    <native:text class="text-2xl font-bold text-theme-on-background">{{ $greeting }}</native:text>
                </native:column>
            @endif

            {{-- Continue Watching --}}
            @if(!empty($continueWatching))
                {{-- Section bands and row dividers match the Calendar screen --}}
                <native:column class="w-full bg-theme-surface-variant px-4 py-3">
                    <native:text class="text-sm font-semibold text-theme-on-surface-variant uppercase">Continue Watching</native:text>
                </native:column>

                @foreach($continueWatching as $entry)
                    @php
                        $title = $entry['title'];
                        $episode = $entry['next_episode'];
                        $episodeLabel = ($episode['code'] ?? '') . ($episode['name'] ? ' · ' . $episode['name'] : '');
                        $isMarking = isset($markingWatched[$episode['id']]);
                    @endphp

                    {{-- The whole row opens the title; Mark Watched is a nested button, which wins its own taps --}}
                    <native:pressable @press="openTitle({{ $title['id'] }})" class="w-full">
                    <native:row class="w-full px-4 py-3 gap-3 items-start">
                        <native:image
                            src="{{ $title['poster_url'] }}"
                            :width="80"
                            :height="120"
                            :fit="2"
                            class="rounded-lg"
                        />

                        <native:column class="flex-1 gap-1">
                            <native:text class="font-semibold text-theme-on-background">{{ $title['name'] }}</native:text>

                            <native:text class="text-theme-on-surface-variant">{{ $episodeLabel }}</native:text>

                            @if(!empty($episode['formatted_air_date']))
                                <native:text class="text-sm text-theme-on-surface-variant">{{ $episode['formatted_air_date'] }}</native:text>
                            @endif

                            @if($episode['has_aired'])
                                <native:button
                                    label="{{ $isMarking ? 'Marking...' : 'Mark Watched' }}"
                                    @press="markWatched({{ $episode['id'] }})"
                                    variant="secondary"
                                    size="sm"
                                    class="mt-2"
                                    disabled="{{ $isMarking }}"
                                    loading="{{ $isMarking }}"
                                />
                            @endif
                        </native:column>
                    </native:row>
                    </native:pressable>

                    <native:column class="w-full h-px bg-theme-outline" />
                @endforeach
            @endif

            {{-- Airing This Week --}}
            @if(!empty($airingThisWeek))

                @foreach($airingThisWeek as $day => $episodes)
                    <native:column class="w-full bg-theme-surface-variant px-4 py-3">
                        <native:text class="text-sm font-semibold text-theme-on-surface-variant uppercase">Airing · {{ $day }}</native:text>
                    </native:column>

                    @foreach($episodes as $episode)
                        @php
                            $episodeTitle = $episode['title'] ?? [];
                            $episodeLabel = ($episode['code'] ?? '') . ($episode['name'] ? ' · ' . $episode['name'] : '');
                            $canMark = $episode['can_mark_watched'] ?? false;
                            $isMarking = isset($markingWatched[$episode['id']]);
                        @endphp

                        @if(!empty($episodeTitle))
                        <native:pressable @press="openTitle({{ $episodeTitle['id'] }})" class="w-full">
                        <native:row class="w-full px-4 py-3 gap-3 items-start">
                                <native:image
                                    src="{{ $episodeTitle['poster_url'] }}"
                                    :width="80"
                                    :height="120"
                                    :fit="2"
                                    class="rounded-lg"
                                />

                                <native:column class="flex-1 gap-1">
                                    <native:text class="font-semibold text-theme-on-background">{{ $episodeTitle['name'] }}</native:text>

                                    <native:text class="text-theme-on-surface-variant">{{ $episodeLabel }}</native:text>

                                    @if(!empty($episode['formatted_air_date']))
                                        <native:text class="text-sm text-theme-on-surface-variant">{{ $episode['formatted_air_date'] }}</native:text>
                                    @endif

                                    @if($canMark)
                                        <native:button
                                            label="{{ $isMarking ? 'Marking...' : 'Mark Watched' }}"
                                            @press="markWatched({{ $episode['id'] }})"
                                            variant="secondary"
                                            size="sm"
                                            class="mt-2"
                                            disabled="{{ $isMarking }}"
                                            loading="{{ $isMarking }}"
                                        />
                                    @endif
                                </native:column>
                        </native:row>
                        </native:pressable>

                        <native:column class="w-full h-px bg-theme-outline" />
                        @endif
                    @endforeach
                @endforeach
            @endif

            {{-- Recently Added to Plex --}}
            @if(!empty($recentlyAdded))
                <native:column class="w-full bg-theme-surface-variant px-4 py-3">
                    <native:text class="text-sm font-semibold text-theme-on-surface-variant uppercase">Recently Added to Plex</native:text>
                </native:column>

                @foreach($recentlyAdded as $title)
                    <native:pressable @press="openTitle({{ $title['id'] }})" class="w-full">
                        <native:row class="w-full px-4 py-3 gap-3 items-center">
                            <native:image
                                src="{{ $title['poster_url'] }}"
                                :width="60"
                                :height="90"
                                :fit="2"
                                class="rounded-lg"
                            />

                            <native:column class="flex-1 gap-1">
                                <native:text class="font-semibold text-theme-on-background">{{ $title['name'] }}</native:text>
                                <native:text class="text-sm text-theme-on-surface-variant">{{ $title['label'] ?? ucfirst($title['type'] ?? '') }}</native:text>

                                @if($title['plex_available'] ?? false)
                                    <native:row class="items-center gap-1 mt-1">
                                        <native:badge label="On Plex" variant="success" size="sm" />
                                    </native:row>
                                @endif
                            </native:column>

                            <native:icon name="forward" :size="20" class="text-theme-on-surface-variant" />
                        </native:row>
                    </native:pressable>

                    <native:column class="w-full h-px bg-theme-outline" />
                @endforeach
            @endif
        </native:refreshable>
    </native:column>
@endif
