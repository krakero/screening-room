<native:column class="w-full h-full bg-theme-background">
    {{-- Search input and toggles --}}
    <native:column class="w-full p-4 pb-2 gap-3">
        <native:outlined-text-input
            native:model.debounce.500ms="query"
            placeholder="Search for movies and shows..."
            keyboard="text"
            @submit="search"
            leading-icon="search"
            class="w-full"
        />

        {{-- Filter toggles --}}
        <native:row class="w-full gap-4">
            <native:row class="gap-2 items-center">
                <native:toggle native:model="hideWatched" />
                <native:text class="text-sm text-theme-on-surface-variant">Hide watched</native:text>
            </native:row>
            <native:row class="gap-2 items-center">
                <native:toggle native:model="hideReReleases" />
                <native:text class="text-sm text-theme-on-surface-variant">Hide re-releases</native:text>
            </native:row>
        </native:row>
    </native:column>

    {{-- Error state for search --}}
    @if($error && !empty(trim($query)))
        <native:column class="w-full p-6 gap-4 items-center justify-center">
            <native:text class="text-theme-error text-center">{{ $error }}</native:text>
            <native:button label="Retry" @press="retry" variant="primary" size="sm" />
        </native:column>
    @endif

    {{-- Loading state for search --}}
    @if($loading && empty($results) && !empty(trim($query)))
        <native:column class="w-full h-full items-center justify-center">
            <native:activity-indicator size="lg" />
            <native:text class="mt-4 text-theme-on-surface-variant">Searching...</native:text>
        </native:column>
    @endif

    {{-- No results for search --}}
    @if(!$loading && empty($results) && $hasSearched && empty($error) && !empty(trim($query)))
        <native:column class="w-full h-full items-center justify-center p-6">
            <native:text class="text-lg text-theme-on-surface-variant text-center">No results found</native:text>
        </native:column>
    @endif

    {{-- Search results list --}}
    @if(!empty($results) && !empty(trim($query)))
        <native:refreshable class="w-full flex-1" @refresh="search">
            @foreach($results as $index => $result)
                @php
                    // Apply filters to search results
                    if (!$this->shouldShowItem($result)) {
                        continue;
                    }
                @endphp

                <native:pressable @press="openResult({{ $index }})">
                    <native:row class="w-full p-4 gap-3 items-start">
                        {{-- Poster --}}
                        <native:column class="flex-shrink-0">
                            @if($result['poster_url'])
                                <native:image
                                    src="{{ $result['poster_url'] }}"
                                    :width="60"
                                    :height="90"
                                    :fit="2"
                                    class="rounded"
                                />
                            @else
                                <native:column class="w-16 h-24 rounded bg-theme-surface-variant items-center justify-center">
                                    <native:text class="text-theme-on-surface-variant text-xs">No Image</native:text>
                                </native:column>
                            @endif
                        </native:column>

                        {{-- Details --}}
                        <native:column class="flex-1 gap-1">
                            {{-- Title and year --}}
                            <native:row class="gap-2 items-center flex-wrap">
                                <native:text class="text-base font-semibold text-theme-on-background">{{ $result['name'] }}</native:text>
                                @if($result['year'])
                                    <native:text class="text-sm text-theme-on-surface-variant">{{ $result['year'] }}</native:text>
                                @endif
                            </native:row>

                            {{-- Type badge and status markers --}}
                            <native:row class="gap-2 items-center flex-wrap">
                                {{-- Movie/Show badge --}}
                                <native:badge
                                    label="{{ $result['type'] === 'movie' ? 'Movie' : 'Show' }}"
                                    variant="{{ $result['type'] === 'movie' ? 'accent' : 'primary' }}"
                                />

                                {{-- Status badges --}}
                                @if($result['watched'])
                                    <native:badge label="Watched" variant="success" />
                                @endif

                                @php
                                    $status = $this->getRequestStatus($index, $result);
                                @endphp

                                @if($status === 'owned')
                                    <native:badge label="Owned" variant="success" />
                                @elseif($status === 'pending')
                                    <native:badge label="Requested" variant="accent" />
                                @elseif($status === 'processing')
                                    <native:badge label="Processing" variant="accent" />
                                @elseif($status === 'available' || $status === 'partially_available')
                                    <native:badge label="Available" variant="success" />
                                @endif

                                @if($result['on_list'])
                                    <native:badge label="On List" variant="primary" />
                                @endif

                                @if($result['followed'] ?? false)
                                    <native:badge label="Following" variant="primary" />
                                @endif
                            </native:row>

                            {{-- Request button --}}
                            @if($this->canRequest($result))
                                <native:row class="mt-1">
                                    @if($this->isImporting($result) || $this->isRequesting($index))
                                        <native:button
                                            label="Requesting..."
                                            variant="secondary"
                                            size="sm"
                                            disabled
                                            loading
                                        />
                                    @else
                                        <native:button
                                            label="Request"
                                            @press="requestTitle({{ $index }})"
                                            variant="secondary"
                                            size="sm"
                                        />
                                    @endif
                                </native:row>
                            @endif
                        </native:column>
                    </native:row>
                </native:pressable>

                {{-- Separator --}}
                @if(!$loop->last)
                    <native:column class="w-full h-px bg-theme-outline" />
                @endif
            @endforeach
        </native:refreshable>
    @endif

    {{-- Discover shelves (when query is empty) --}}
    @if(empty(trim($query)))
        <native:refreshable class="w-full flex-1" @refresh="refresh">
            <native:column class="w-full gap-6 pb-24">
                {{-- Trending this week --}}
                @if($trendingError)
                    <native:column class="w-full gap-3">
                        <native:column class="w-full bg-theme-surface-variant px-4 py-3">
                            <native:text class="text-sm font-semibold text-theme-on-surface-variant uppercase">Trending this week</native:text>
                        </native:column>
                        <native:column class="px-4">
                            <native:text class="text-theme-error">{{ $trendingError }}</native:text>
                        </native:column>
                    </native:column>
                @elseif(!empty($trending))
                    @php
                        $filteredTrending = $this->filterItems($trending);
                    @endphp
                    @if(!empty($filteredTrending))
                        <native:column class="w-full gap-3">
                            <native:column class="w-full bg-theme-surface-variant px-4 py-3">
                                <native:text class="text-sm font-semibold text-theme-on-surface-variant uppercase">Trending this week</native:text>
                            </native:column>
                            <native:scroll-view horizontal class="w-full">
                                <native:row class="gap-3 px-4">
                                    @foreach($trending as $index => $item)
                                        @if($this->shouldShowItem($item))
                                            <native:pressable @press="openItem('trending', {{ $index }})">
                                                <native:column class="gap-2" style="width: 112px;">
                                                    @if($item['poster_url'])
                                                        <native:image
                                                            src="{{ $item['poster_url'] }}"
                                                            :width="112"
                                                            :height="168"
                                                            :fit="2"
                                                            class="rounded-lg"
                                                        />
                                                    @else
                                                        <native:column class="w-28 h-42 rounded-lg bg-theme-surface-variant items-center justify-center">
                                                            <native:text class="text-theme-on-surface-variant text-xs text-center px-2">No Image</native:text>
                                                        </native:column>
                                                    @endif
                                                    <native:column class="gap-1">
                                                        <native:text class="text-sm text-theme-on-background" :lines="1">{{ $item['name'] }}</native:text>
                                                        @if($item['year'])
                                                            <native:text class="text-xs text-theme-on-surface-variant">{{ $item['year'] }}</native:text>
                                                        @endif
                                                        @if($item['watched'])
                                                            <native:badge label="Watched" variant="success" size="xs" />
                                                        @endif
                                                    </native:column>
                                                </native:column>
                                            </native:pressable>
                                        @endif
                                    @endforeach
                                </native:row>
                            </native:scroll-view>
                        </native:column>
                    @endif
                @endif

                {{-- In theaters & coming soon --}}
                @if($newMoviesError)
                    <native:column class="w-full gap-3">
                        <native:column class="w-full bg-theme-surface-variant px-4 py-3">
                            <native:text class="text-sm font-semibold text-theme-on-surface-variant uppercase">In theaters & coming soon</native:text>
                        </native:column>
                        <native:column class="px-4">
                            <native:text class="text-theme-error">{{ $newMoviesError }}</native:text>
                        </native:column>
                    </native:column>
                @elseif(!empty($newMovies))
                    @php
                        $filteredNew = $this->filterItems($newMovies);
                    @endphp
                    @if(!empty($filteredNew))
                        <native:column class="w-full gap-3">
                            <native:column class="w-full bg-theme-surface-variant px-4 py-3">
                                <native:text class="text-sm font-semibold text-theme-on-surface-variant uppercase">In theaters & coming soon</native:text>
                            </native:column>
                            <native:scroll-view horizontal class="w-full">
                                <native:row class="gap-3 px-4">
                                    @foreach($newMovies as $index => $item)
                                        @if($this->shouldShowItem($item))
                                            <native:pressable @press="openItem('new', {{ $index }})">
                                                <native:column class="gap-2" style="width: 112px;">
                                                    @if($item['poster_url'])
                                                        <native:image
                                                            src="{{ $item['poster_url'] }}"
                                                            :width="112"
                                                            :height="168"
                                                            :fit="2"
                                                            class="rounded-lg"
                                                        />
                                                    @else
                                                        <native:column class="w-28 h-42 rounded-lg bg-theme-surface-variant items-center justify-center">
                                                            <native:text class="text-theme-on-surface-variant text-xs text-center px-2">No Image</native:text>
                                                        </native:column>
                                                    @endif
                                                    <native:column class="gap-1">
                                                        <native:text class="text-sm text-theme-on-background" :lines="1">{{ $item['name'] }}</native:text>
                                                        @if($item['year'])
                                                            <native:text class="text-xs text-theme-on-surface-variant">{{ $item['year'] }}</native:text>
                                                        @endif
                                                        @if($item['watched'])
                                                            <native:badge label="Watched" variant="success" size="xs" />
                                                        @endif
                                                    </native:column>
                                                </native:column>
                                            </native:pressable>
                                        @endif
                                    @endforeach
                                </native:row>
                            </native:scroll-view>
                        </native:column>
                    @endif
                @endif

                {{-- New episodes this week --}}
                @if($newEpisodesError)
                    <native:column class="w-full gap-3">
                        <native:column class="w-full bg-theme-surface-variant px-4 py-3">
                            <native:text class="text-sm font-semibold text-theme-on-surface-variant uppercase">New episodes this week</native:text>
                        </native:column>
                        <native:column class="px-4">
                            <native:text class="text-theme-error">{{ $newEpisodesError }}</native:text>
                        </native:column>
                    </native:column>
                @elseif(!empty($newEpisodes))
                    @php
                        $filteredEpisodes = $this->filterItems($newEpisodes);
                    @endphp
                    @if(!empty($filteredEpisodes))
                        <native:column class="w-full gap-3">
                            <native:column class="w-full bg-theme-surface-variant px-4 py-3">
                                <native:text class="text-sm font-semibold text-theme-on-surface-variant uppercase">New episodes this week</native:text>
                            </native:column>
                            <native:scroll-view horizontal class="w-full">
                                <native:row class="gap-3 px-4">
                                    @foreach($newEpisodes as $index => $item)
                                        @if($this->shouldShowItem($item))
                                            <native:pressable @press="openItem('episodes', {{ $index }})">
                                                <native:column class="gap-2" style="width: 112px;">
                                                    @if($item['poster_url'])
                                                        <native:image
                                                            src="{{ $item['poster_url'] }}"
                                                            :width="112"
                                                            :height="168"
                                                            :fit="2"
                                                            class="rounded-lg"
                                                        />
                                                    @else
                                                        <native:column class="w-28 h-42 rounded-lg bg-theme-surface-variant items-center justify-center">
                                                            <native:text class="text-theme-on-surface-variant text-xs text-center px-2">No Image</native:text>
                                                        </native:column>
                                                    @endif
                                                    <native:column class="gap-1">
                                                        <native:text class="text-sm text-theme-on-background" :lines="1">{{ $item['name'] }}</native:text>
                                                        @if($item['year'])
                                                            <native:text class="text-xs text-theme-on-surface-variant">{{ $item['year'] }}</native:text>
                                                        @endif
                                                        @if($item['watched'])
                                                            <native:badge label="Watched" variant="success" size="xs" />
                                                        @endif
                                                    </native:column>
                                                </native:column>
                                            </native:pressable>
                                        @endif
                                    @endforeach
                                </native:row>
                            </native:scroll-view>
                        </native:column>
                    @endif
                @endif

                {{-- Recommendations --}}
                @if($recommendationsError)
                    <native:column class="w-full gap-3">
                        <native:column class="w-full bg-theme-surface-variant px-4 py-3">
                            <native:text class="text-sm font-semibold text-theme-on-surface-variant uppercase">Recommendations</native:text>
                        </native:column>
                        <native:column class="px-4">
                            <native:text class="text-theme-error">{{ $recommendationsError }}</native:text>
                        </native:column>
                    </native:column>
                @elseif(!empty($recommendations))
                    @foreach($recommendations as $shelfIndex => $shelf)
                        @php
                            $filteredShelf = $this->filterItems($shelf['items']);
                        @endphp
                        @if(!empty($filteredShelf))
                            <native:column class="w-full gap-3">
                                <native:column class="w-full bg-theme-surface-variant px-4 py-3">
                                    <native:text class="text-sm font-semibold text-theme-on-surface-variant uppercase">{{ $shelf['heading'] }}</native:text>
                                </native:column>
                                <native:scroll-view horizontal class="w-full">
                                    <native:row class="gap-3 px-4">
                                        @foreach($shelf['items'] as $itemIndex => $item)
                                            @if($this->shouldShowItem($item))
                                                <native:pressable @press="openRecommendation({{ $shelfIndex }}, {{ $itemIndex }})">
                                                    <native:column class="gap-2" style="width: 112px;">
                                                        @if($item['poster_url'])
                                                            <native:image
                                                                src="{{ $item['poster_url'] }}"
                                                                :width="112"
                                                                :height="168"
                                                                :fit="2"
                                                                class="rounded-lg"
                                                            />
                                                        @else
                                                            <native:column class="w-28 h-42 rounded-lg bg-theme-surface-variant items-center justify-center">
                                                                <native:text class="text-theme-on-surface-variant text-xs text-center px-2">No Image</native:text>
                                                            </native:column>
                                                        @endif
                                                        <native:column class="gap-1">
                                                            <native:text class="text-sm text-theme-on-background" :lines="1">{{ $item['name'] }}</native:text>
                                                            @if($item['year'])
                                                                <native:text class="text-xs text-theme-on-surface-variant">{{ $item['year'] }}</native:text>
                                                            @endif
                                                            @if($item['watched'])
                                                                <native:badge label="Watched" variant="success" size="xs" />
                                                            @endif
                                                        </native:column>
                                                    </native:column>
                                                </native:pressable>
                                            @endif
                                        @endforeach
                                    </native:row>
                                </native:scroll-view>
                            </native:column>
                        @endif
                    @endforeach
                @endif

                {{-- Empty state when all shelves fail or are filtered out --}}
                @if(!$shelvesLoaded)
                    <native:column class="w-full h-full items-center justify-center p-6">
                        <native:activity-indicator size="lg" />
                        <native:text class="mt-4 text-theme-on-surface-variant">Loading...</native:text>
                    </native:column>
                @elseif(
                    ($trendingError || empty($this->filterItems($trending))) &&
                    ($newMoviesError || empty($this->filterItems($newMovies))) &&
                    ($newEpisodesError || empty($this->filterItems($newEpisodes))) &&
                    ($recommendationsError || empty(collect($recommendations)->filter(fn($s) => !empty($this->filterItems($s['items'])))->all()))
                )
                    <native:column class="w-full h-full items-center justify-center p-6">
                        <native:text class="text-lg text-theme-on-surface-variant text-center">Nothing to discover</native:text>
                        @if($trendingError || $newMoviesError || $newEpisodesError || $recommendationsError)
                            <native:button label="Retry" @press="retry" variant="primary" size="sm" class="mt-4" />
                        @endif
                    </native:column>
                @endif
            </native:column>
        </native:refreshable>
    @endif
</native:column>
