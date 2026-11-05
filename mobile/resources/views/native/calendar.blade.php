<native:column class="w-full h-full bg-theme-background">
    @if($loading && empty($entries))
        {{-- Initial loading state --}}
        <native:column class="w-full h-full items-center justify-center gap-4">
            <native:activity-indicator size="lg" />
            <native:text class="text-theme-on-surface-variant">Loading calendar...</native:text>
        </native:column>
    @elseif($error)
        {{-- Error state --}}
        <native:column class="w-full h-full items-center justify-center gap-4 p-6">
            <native:text class="text-theme-error text-center">{{ $error }}</native:text>
            <native:button label="Retry" variant="primary" @press="retry" />
        </native:column>
    @elseif(empty($entries) && empty($catchUpEntries))
        {{-- Empty state --}}
        <native:column class="w-full h-full items-center justify-center gap-4 p-6">
            <native:text class="text-xl font-semibold text-theme-on-background">No Upcoming Releases</native:text>
            <native:text class="text-theme-on-surface-variant text-center">Follow some shows to see their upcoming episodes here</native:text>
        </native:column>
    @else
        {{-- Content --}}
        <native:refreshable class="w-full h-full" @refresh="refresh">
            {{-- Catch-up section --}}
            @if(!empty($catchUpEntries))
                <native:column class="w-full bg-theme-surface-variant px-4 py-3">
                    <native:text class="text-sm font-semibold text-theme-on-surface-variant uppercase">Catch Up</native:text>
                </native:column>

                @foreach($catchUpEntries as $entry)
                    <native:pressable @press="openTitle({{ $entry['title']['id'] }})">
                        <native:row class="w-full px-4 py-3 gap-3 items-center bg-theme-background">
                            {{-- Poster thumbnail --}}
                            <native:image
                                src="{{ $entry['title']['poster_url'] }}"
                                class="w-16 h-24 rounded-lg"
                            />

                            {{-- Episode details --}}
                            <native:column class="flex-1 gap-1">
                                <native:text class="text-base font-semibold text-theme-on-background">{{ $entry['title']['name'] }}</native:text>

                                @if(isset($entry['episode']))
                                    <native:text class="text-sm text-theme-on-surface-variant">
                                        {{ $entry['episode']['code'] }} · {{ $entry['episode']['name'] }}
                                    </native:text>
                                @else
                                    <native:text class="text-sm text-theme-on-surface-variant">{{ $entry['type'] }}</native:text>
                                @endif

                                @if(isset($entry['episode']) && isset($entry['episode']['has_aired']) && $entry['episode']['has_aired'])
                                    <native:row class="gap-2 items-center">
                                        @if($entry['watched'] ?? false)
                                            <native:icon name="check-circle" class="w-4 h-4 text-theme-primary" />
                                            <native:text class="text-xs text-theme-primary">Watched</native:text>
                                        @else
                                            <native:text class="text-xs text-theme-on-surface-variant">Mark watched</native:text>
                                        @endif
                                    </native:row>
                                @endif
                            </native:column>

                            {{-- Forward icon --}}
                            <native:icon name="chevron-right" class="w-5 h-5 text-theme-on-surface-variant" />
                        </native:row>
                    </native:pressable>

                    <native:column class="w-full h-px bg-theme-outline" />
                @endforeach
            @endif

            {{-- Grouped calendar entries --}}
            @foreach($groupedEntries as $dateHeader => $dayData)
                <native:column class="w-full bg-theme-surface-variant px-4 py-3">
                    <native:text class="text-sm font-semibold text-theme-on-surface-variant uppercase">
                        {{ $this->formatDateHeaderWithDate($dayData['date']) }}
                    </native:text>
                </native:column>

                @foreach($dayData['entries'] as $entry)
                    <native:pressable @press="openTitle({{ $entry['title']['id'] }})">
                        <native:row class="w-full px-4 py-3 gap-3 items-center bg-theme-background">
                            {{-- Poster thumbnail --}}
                            <native:image
                                src="{{ $entry['title']['poster_url'] }}"
                                class="w-16 h-24 rounded-lg"
                            />

                            {{-- Episode details --}}
                            <native:column class="flex-1 gap-1">
                                <native:text class="text-base font-semibold text-theme-on-background">{{ $entry['title']['name'] }}</native:text>

                                @if(isset($entry['_grouped']) && $entry['_grouped'])
                                    {{-- Grouped consecutive episodes --}}
                                    <native:text class="text-sm text-theme-on-surface-variant">
                                        {{ $entry['_firstEpisode']['code'] }}–{{ $entry['_lastEpisode']['code'] }} · {{ $entry['_episodeCount'] }} episodes
                                    </native:text>
                                    @if($entry['_allWatched'] ?? false)
                                        <native:row class="gap-2 items-center">
                                            <native:icon name="check-circle" class="w-4 h-4 text-theme-primary" />
                                            <native:text class="text-xs text-theme-primary">All watched</native:text>
                                        </native:row>
                                    @endif
                                @elseif(isset($entry['episode']))
                                    {{-- Single episode --}}
                                    <native:text class="text-sm text-theme-on-surface-variant">
                                        {{ $entry['episode']['code'] }} · {{ $entry['episode']['name'] }}
                                    </native:text>

                                    @if(isset($entry['episode']['has_aired']) && $entry['episode']['has_aired'])
                                        <native:row class="gap-2 items-center">
                                            @if($entry['watched'] ?? false)
                                                <native:icon name="check-circle" class="w-4 h-4 text-theme-primary" />
                                                <native:text class="text-xs text-theme-primary">Watched</native:text>
                                            @else
                                                <native:text class="text-xs text-theme-on-surface-variant">Mark watched</native:text>
                                            @endif
                                        </native:row>
                                    @endif
                                @else
                                    <native:text class="text-sm text-theme-on-surface-variant">{{ $entry['type'] }}</native:text>
                                @endif
                            </native:column>

                            {{-- Forward icon --}}
                            <native:icon name="chevron-right" class="w-5 h-5 text-theme-on-surface-variant" />
                        </native:row>
                    </native:pressable>

                    <native:column class="w-full h-px bg-theme-outline" />
                @endforeach
            @endforeach

            {{-- Load more button --}}
            @if(!$loadingMore && !empty($entries))
                <native:column class="w-full px-4 py-4">
                    <native:button label="Load More" variant="secondary" @press="loadMore" />
                </native:column>
            @endif

            {{-- Loading more indicator --}}
            @if($loadingMore)
                <native:column class="w-full items-center py-4">
                    <native:activity-indicator />
                </native:column>
            @endif
        </native:refreshable>
    @endif
</native:column>
