<?php

namespace Tests\Feature;

use App\NativeComponents\Search;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Native\Mobile\Testing\Native;
use Tests\TestCase;

class SearchScreenTest extends TestCase
{
    use RefreshDatabase;

    private array $storedData = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->storedData = [];
        $bridge = Native::fakeBridge();

        // SecureStorage fake
        $bridge->respondTo('SecureStorage.Get', function ($params) {
            $key = $params['key'];
            if (isset($this->storedData[$key])) {
                return ['status' => 'Found', 'value' => $this->storedData[$key]];
            }

            return ['status' => 'NotFound', 'value' => null];
        });

        $bridge->respondTo('SecureStorage.Set', function ($params) {
            if ($params['value'] === null) {
                unset($this->storedData[$params['key']]);
            } else {
                $this->storedData[$params['key']] = $params['value'];
            }

            return ['success' => true];
        });

        $bridge->respondTo('SecureStorage.Delete', function ($params) {
            unset($this->storedData[$params['key']]);

            return ['success' => true];
        });

        // Set up authenticated state
        $this->storedData['server_url'] = 'https://example.com';
        $this->storedData['api_token'] = 'test-token';
    }

    public function test_loads_discover_shelves_on_mount(): void
    {
        Http::fake([
            'https://example.com/api/v1/discover/trending' => Http::response([
                'data' => [
                    [
                        'tmdb_id' => 123,
                        'type' => 'movie',
                        'name' => 'Trending Movie',
                        'year' => '2026',
                        'poster_url' => 'https://example.com/poster.jpg',
                        'title_id' => null,
                        'watched' => false,
                        'status' => null,
                        'on_list' => false,
                        'followed' => false,
                        'is_re_release' => false,
                    ],
                ],
            ]),
            'https://example.com/api/v1/discover/new' => Http::response(['data' => []]),
            'https://example.com/api/v1/discover/new-episodes' => Http::response(['data' => []]),
            'https://example.com/api/v1/discover/recommendations' => Http::response(['data' => []]),
        ]);

        $testable = Native::test(Search::class);

        $testable->assertSet('shelvesLoaded', true);
        $this->assertNotEmpty($testable->get('trending'));
        $testable->assertSee('Trending Movie');
    }

    public function test_shows_section_headings(): void
    {
        Http::fake([
            'https://example.com/api/v1/discover/trending' => Http::response(['data' => [['tmdb_id' => 1, 'type' => 'movie', 'name' => 'Test', 'year' => '2026', 'poster_url' => null, 'title_id' => null, 'watched' => false, 'status' => null, 'on_list' => false, 'followed' => false, 'is_re_release' => false]]]),
            'https://example.com/api/v1/discover/new' => Http::response(['data' => []]),
            'https://example.com/api/v1/discover/new-episodes' => Http::response(['data' => []]),
            'https://example.com/api/v1/discover/recommendations' => Http::response(['data' => []]),
        ]);

        $testable = Native::test(Search::class);

        $testable->assertSee('Trending this week');
    }

    public function test_shows_recommendation_shelves(): void
    {
        Http::fake([
            'https://example.com/api/v1/discover/trending' => Http::response(['data' => []]),
            'https://example.com/api/v1/discover/new' => Http::response(['data' => []]),
            'https://example.com/api/v1/discover/new-episodes' => Http::response(['data' => []]),
            'https://example.com/api/v1/discover/recommendations' => Http::response([
                'data' => [
                    [
                        'heading' => 'Because you watched Test Show',
                        'items' => [
                            [
                                'tmdb_id' => 456,
                                'type' => 'show',
                                'name' => 'Similar Show',
                                'year' => '2025',
                                'poster_url' => 'https://example.com/poster2.jpg',
                                'title_id' => null,
                                'watched' => false,
                                'status' => null,
                                'on_list' => false,
                                'followed' => false,
                                'is_re_release' => false,
                            ],
                        ],
                    ],
                ],
            ]),
        ]);

        $testable = Native::test(Search::class);

        $testable->assertSee('Because you watched Test Show');
        $testable->assertSee('Similar Show');
    }

    public function test_opens_title_from_shelf(): void
    {
        Http::fake([
            'https://example.com/api/v1/discover/trending' => Http::response([
                'data' => [
                    [
                        'tmdb_id' => 123,
                        'type' => 'movie',
                        'name' => 'Test Movie',
                        'year' => '2026',
                        'poster_url' => null,
                        'title_id' => 42,
                        'watched' => false,
                        'status' => null,
                        'on_list' => false,
                        'followed' => false,
                        'is_re_release' => false,
                    ],
                ],
            ]),
            'https://example.com/api/v1/discover/new' => Http::response(['data' => []]),
            'https://example.com/api/v1/discover/new-episodes' => Http::response(['data' => []]),
            'https://example.com/api/v1/discover/recommendations' => Http::response(['data' => []]),
        ]);

        $testable = Native::test(Search::class)
            ->call('openItem', 'trending', 0);

        $testable->assertNavigatedTo('/titles/42');
    }

    public function test_imports_and_opens_item_from_shelf(): void
    {
        Http::fake([
            'https://example.com/api/v1/discover/trending' => Http::response([
                'data' => [
                    [
                        'tmdb_id' => 123,
                        'type' => 'movie',
                        'name' => 'Test Movie',
                        'year' => '2026',
                        'poster_url' => null,
                        'title_id' => null,
                        'watched' => false,
                        'status' => null,
                        'on_list' => false,
                        'followed' => false,
                        'is_re_release' => false,
                    ],
                ],
            ]),
            'https://example.com/api/v1/discover/new' => Http::response(['data' => []]),
            'https://example.com/api/v1/discover/new-episodes' => Http::response(['data' => []]),
            'https://example.com/api/v1/discover/recommendations' => Http::response(['data' => []]),
            'https://example.com/api/v1/titles/import' => Http::response([
                'id' => 99,
                'type' => 'movie',
                'tmdb_id' => 123,
                'name' => 'Test Movie',
            ], 201),
        ]);

        $testable = Native::test(Search::class)
            ->call('openItem', 'trending', 0);

        Http::assertSent(function ($request) {
            return $request->url() === 'https://example.com/api/v1/titles/import'
                && $request['type'] === 'movie'
                && $request['tmdb_id'] === 123;
        });

        $testable->assertNavigatedTo('/titles/99');
    }

    public function test_toggles_filter_watched_items(): void
    {
        Http::fake([
            'https://example.com/api/v1/discover/trending' => Http::response([
                'data' => [
                    [
                        'tmdb_id' => 1,
                        'type' => 'movie',
                        'name' => 'Watched Movie',
                        'year' => '2026',
                        'poster_url' => null,
                        'title_id' => 1,
                        'watched' => true,
                        'status' => null,
                        'on_list' => false,
                        'followed' => false,
                        'is_re_release' => false,
                    ],
                    [
                        'tmdb_id' => 2,
                        'type' => 'movie',
                        'name' => 'Unwatched Movie',
                        'year' => '2026',
                        'poster_url' => null,
                        'title_id' => 2,
                        'watched' => false,
                        'status' => null,
                        'on_list' => false,
                        'followed' => false,
                        'is_re_release' => false,
                    ],
                ],
            ]),
            'https://example.com/api/v1/discover/new' => Http::response(['data' => []]),
            'https://example.com/api/v1/discover/new-episodes' => Http::response(['data' => []]),
            'https://example.com/api/v1/discover/recommendations' => Http::response(['data' => []]),
        ]);

        $testable = Native::test(Search::class);

        // Initially both visible
        $filtered = $testable->get('hideWatched') ? $testable->instance()->filterItems($testable->get('trending')) : $testable->get('trending');
        $this->assertCount(2, $filtered);

        // Enable hide watched
        $testable->set('hideWatched', true);
        $filtered = $testable->instance()->filterItems($testable->get('trending'));
        $this->assertCount(1, $filtered);
        $this->assertEquals('Unwatched Movie', $filtered[0]['name']);
    }

    public function test_toggles_filter_re_releases(): void
    {
        Http::fake([
            'https://example.com/api/v1/discover/trending' => Http::response([
                'data' => [
                    [
                        'tmdb_id' => 1,
                        'type' => 'movie',
                        'name' => 'Re-release',
                        'year' => '2026',
                        'poster_url' => null,
                        'title_id' => 1,
                        'watched' => false,
                        'status' => null,
                        'on_list' => false,
                        'followed' => false,
                        'is_re_release' => true,
                    ],
                    [
                        'tmdb_id' => 2,
                        'type' => 'movie',
                        'name' => 'New Release',
                        'year' => '2026',
                        'poster_url' => null,
                        'title_id' => 2,
                        'watched' => false,
                        'status' => null,
                        'on_list' => false,
                        'followed' => false,
                        'is_re_release' => false,
                    ],
                ],
            ]),
            'https://example.com/api/v1/discover/new' => Http::response(['data' => []]),
            'https://example.com/api/v1/discover/new-episodes' => Http::response(['data' => []]),
            'https://example.com/api/v1/discover/recommendations' => Http::response(['data' => []]),
        ]);

        $testable = Native::test(Search::class);

        // Enable hide re-releases
        $testable->set('hideReReleases', true);
        $filtered = $testable->instance()->filterItems($testable->get('trending'));
        $this->assertCount(1, $filtered);
        $this->assertEquals('New Release', $filtered[0]['name']);
    }

    public function test_hides_shelf_when_empty_after_filtering(): void
    {
        Http::fake([
            'https://example.com/api/v1/discover/trending' => Http::response([
                'data' => [
                    [
                        'tmdb_id' => 1,
                        'type' => 'movie',
                        'name' => 'Watched Movie',
                        'year' => '2026',
                        'poster_url' => null,
                        'title_id' => 1,
                        'watched' => true,
                        'status' => null,
                        'on_list' => false,
                        'followed' => false,
                        'is_re_release' => false,
                    ],
                ],
            ]),
            'https://example.com/api/v1/discover/new' => Http::response(['data' => []]),
            'https://example.com/api/v1/discover/new-episodes' => Http::response(['data' => []]),
            'https://example.com/api/v1/discover/recommendations' => Http::response(['data' => []]),
        ]);

        $testable = Native::test(Search::class);
        $testable->set('hideWatched', true);

        $filtered = $testable->instance()->filterItems($testable->get('trending'));
        $this->assertEmpty($filtered);
    }

    public function test_one_failing_endpoint_hides_only_that_shelf(): void
    {
        Http::fake([
            'https://example.com/api/v1/discover/trending' => Http::response(['message' => 'Error'], 500),
            'https://example.com/api/v1/discover/new' => Http::response([
                'data' => [
                    [
                        'tmdb_id' => 1,
                        'type' => 'movie',
                        'name' => 'New Movie',
                        'year' => '2026',
                        'poster_url' => null,
                        'title_id' => null,
                        'watched' => false,
                        'status' => null,
                        'on_list' => false,
                        'followed' => false,
                        'is_re_release' => false,
                    ],
                ],
            ]),
            'https://example.com/api/v1/discover/new-episodes' => Http::response(['data' => []]),
            'https://example.com/api/v1/discover/recommendations' => Http::response(['data' => []]),
        ]);

        $testable = Native::test(Search::class);

        $this->assertNotEmpty($testable->get('trendingError'));
        $this->assertEmpty($testable->get('trending'));
        $this->assertEmpty($testable->get('newMoviesError'));
        $this->assertNotEmpty($testable->get('newMovies'));
        $testable->assertSee('New Movie');
    }

    public function test_query_switches_to_search_results(): void
    {
        Http::fake([
            'https://example.com/api/v1/discover/trending' => Http::response(['data' => [['tmdb_id' => 1, 'type' => 'movie', 'name' => 'Trending', 'year' => '2026', 'poster_url' => null, 'title_id' => null, 'watched' => false, 'status' => null, 'on_list' => false, 'followed' => false, 'is_re_release' => false]]]),
            'https://example.com/api/v1/discover/new' => Http::response(['data' => []]),
            'https://example.com/api/v1/discover/new-episodes' => Http::response(['data' => []]),
            'https://example.com/api/v1/discover/recommendations' => Http::response(['data' => []]),
            'https://example.com/api/v1/search*' => Http::response([
                'data' => [
                    [
                        'tmdb_id' => 603,
                        'type' => 'movie',
                        'name' => 'The Matrix',
                        'year' => '1999',
                        'poster_url' => 'https://image.tmdb.org/t/p/w342/poster.jpg',
                        'title_id' => 1,
                        'watched' => false,
                        'status' => 'owned',
                        'on_list' => false,
                        'followed' => false,
                        'is_re_release' => false,
                    ],
                ],
            ]),
        ]);

        $testable = Native::test(Search::class)
            ->set('query', 'matrix')
            ->call('search');

        $testable->assertSet('hasSearched', true);
        $testable->assertSee('The Matrix');
        $testable->assertDontSee('TRENDING THIS WEEK');
    }

    public function test_searches_and_displays_results(): void
    {
        Http::fake([
            'https://example.com/api/v1/discover/trending' => Http::response(['data' => []]),
            'https://example.com/api/v1/discover/new' => Http::response(['data' => []]),
            'https://example.com/api/v1/discover/new-episodes' => Http::response(['data' => []]),
            'https://example.com/api/v1/discover/recommendations' => Http::response(['data' => []]),
            'https://example.com/api/v1/search*' => Http::response([
                'data' => [
                    [
                        'tmdb_id' => 603,
                        'type' => 'movie',
                        'name' => 'The Matrix',
                        'year' => '1999',
                        'poster_url' => 'https://image.tmdb.org/t/p/w342/poster.jpg',
                        'title_id' => 1,
                        'watched' => false,
                        'status' => 'owned',
                        'on_list' => false,
                        'followed' => false,
                        'is_re_release' => false,
                    ],
                    [
                        'tmdb_id' => 1396,
                        'type' => 'show',
                        'name' => 'Breaking Bad',
                        'year' => '2008',
                        'poster_url' => 'https://image.tmdb.org/t/p/w342/poster2.jpg',
                        'title_id' => null,
                        'watched' => false,
                        'status' => null,
                        'on_list' => false,
                        'followed' => false,
                        'is_re_release' => false,
                    ],
                ],
            ]),
        ]);

        $testable = Native::test(Search::class)
            ->set('query', 'matrix')
            ->call('search');

        $testable->assertSet('loading', false);
        $testable->assertSet('hasSearched', true);
        $testable->assertSee('The Matrix');
        $testable->assertSee('1999');
        $testable->assertSee('Breaking Bad');
        $testable->assertSee('2008');

        Http::assertSent(function ($request) {
            return $request->url() === 'https://example.com/api/v1/search?q=matrix'
                && $request->hasHeader('Authorization', 'Bearer test-token');
        });
    }

    public function test_shows_no_results_when_empty(): void
    {
        Http::fake([
            'https://example.com/api/v1/discover/trending' => Http::response(['data' => []]),
            'https://example.com/api/v1/discover/new' => Http::response(['data' => []]),
            'https://example.com/api/v1/discover/new-episodes' => Http::response(['data' => []]),
            'https://example.com/api/v1/discover/recommendations' => Http::response(['data' => []]),
            'https://example.com/api/v1/search*' => Http::response(['data' => []]),
        ]);

        $testable = Native::test(Search::class)
            ->set('query', 'nonexistent')
            ->call('search');

        $testable->assertSet('loading', false);
        $testable->assertSet('hasSearched', true);
        $testable->assertSet('results', []);
        $testable->assertSee('No results found');
    }

    public function test_clears_results_when_query_is_empty(): void
    {
        Http::fake([
            'https://example.com/api/v1/discover/trending' => Http::response(['data' => []]),
            'https://example.com/api/v1/discover/new' => Http::response(['data' => []]),
            'https://example.com/api/v1/discover/new-episodes' => Http::response(['data' => []]),
            'https://example.com/api/v1/discover/recommendations' => Http::response(['data' => []]),
        ]);

        $testable = Native::test(Search::class);

        // Set some state as if we had searched
        $testable->set('results', [[
            'tmdb_id' => 1,
            'type' => 'movie',
            'name' => 'Test',
            'year' => '2024',
            'poster_url' => null,
            'title_id' => null,
            'watched' => false,
            'status' => null,
            'on_list' => false,
            'followed' => false,
            'is_re_release' => false,
        ]]);
        $testable->set('hasSearched', true);

        // Clear query and search
        $testable->set('query', '');
        $testable->call('search');

        $testable->assertSet('results', []);
        $testable->assertSet('hasSearched', false);
    }

    public function test_handles_api_error_and_shows_retry_button(): void
    {
        Http::fake([
            'https://example.com/api/v1/discover/trending' => Http::response(['data' => []]),
            'https://example.com/api/v1/discover/new' => Http::response(['data' => []]),
            'https://example.com/api/v1/discover/new-episodes' => Http::response(['data' => []]),
            'https://example.com/api/v1/discover/recommendations' => Http::response(['data' => []]),
            'https://example.com/api/v1/search*' => Http::response(['message' => 'Server error'], 500),
        ]);

        $testable = Native::test(Search::class)
            ->set('query', 'test')
            ->call('search');

        $testable->assertSet('loading', false);
        $this->assertEquals('The server had a problem. Please try again.', $testable->get('error'));
        $testable->assertSee('Retry');
    }

    public function test_retry_calls_search_again(): void
    {
        Http::fake([
            'https://example.com/api/v1/discover/trending' => Http::response(['data' => []]),
            'https://example.com/api/v1/discover/new' => Http::response(['data' => []]),
            'https://example.com/api/v1/discover/new-episodes' => Http::response(['data' => []]),
            'https://example.com/api/v1/discover/recommendations' => Http::response(['data' => []]),
            'https://example.com/api/v1/search*' => Http::response(['data' => []], 200),
        ]);

        $testable = Native::test(Search::class)
            ->set('query', 'test')
            ->set('error', 'Previous error')
            ->call('retry');

        // Retry should clear error and search
        $testable->assertSet('error', '');
        $testable->assertSet('hasSearched', true);
        $testable->assertSet('loading', false);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/api/v1/search');
        });
    }

    public function test_opens_result_with_title_id(): void
    {
        Http::fake([
            'https://example.com/api/v1/discover/trending' => Http::response(['data' => []]),
            'https://example.com/api/v1/discover/new' => Http::response(['data' => []]),
            'https://example.com/api/v1/discover/new-episodes' => Http::response(['data' => []]),
            'https://example.com/api/v1/discover/recommendations' => Http::response(['data' => []]),
        ]);

        $testable = Native::test(Search::class);
        $testable->set('results', [
            [
                'tmdb_id' => 603,
                'type' => 'movie',
                'name' => 'The Matrix',
                'year' => '1999',
                'poster_url' => 'https://image.tmdb.org/t/p/w342/poster.jpg',
                'title_id' => 42,
                'watched' => false,
                'status' => 'owned',
                'on_list' => false,
                'followed' => false,
                'is_re_release' => false,
            ],
        ]);

        $testable->call('openResult', 0);

        $testable->assertNavigatedTo('/titles/42');
    }

    public function test_imports_and_opens_tmdb_only_result(): void
    {
        Http::fake([
            'https://example.com/api/v1/discover/trending' => Http::response(['data' => []]),
            'https://example.com/api/v1/discover/new' => Http::response(['data' => []]),
            'https://example.com/api/v1/discover/new-episodes' => Http::response(['data' => []]),
            'https://example.com/api/v1/discover/recommendations' => Http::response(['data' => []]),
            'https://example.com/api/v1/titles/import' => Http::response([
                'id' => 123,
                'type' => 'movie',
                'tmdb_id' => 603,
                'name' => 'The Matrix',
            ], 201),
        ]);

        $testable = Native::test(Search::class);
        $testable->set('results', [
            [
                'tmdb_id' => 603,
                'type' => 'movie',
                'name' => 'The Matrix',
                'year' => '1999',
                'poster_url' => 'https://image.tmdb.org/t/p/w342/poster.jpg',
                'title_id' => null,
                'watched' => false,
                'status' => null,
                'on_list' => false,
                'followed' => false,
                'is_re_release' => false,
            ],
        ]);

        $testable->call('openResult', 0);

        Http::assertSent(function ($request) {
            return $request->url() === 'https://example.com/api/v1/titles/import'
                && $request['type'] === 'movie'
                && $request['tmdb_id'] === 603;
        });

        $testable->assertNavigatedTo('/titles/123');
    }

    public function test_requests_title_with_title_id(): void
    {
        Http::fake([
            'https://example.com/api/v1/discover/trending' => Http::response(['data' => []]),
            'https://example.com/api/v1/discover/new' => Http::response(['data' => []]),
            'https://example.com/api/v1/discover/new-episodes' => Http::response(['data' => []]),
            'https://example.com/api/v1/discover/recommendations' => Http::response(['data' => []]),
            'https://example.com/api/v1/titles/42/request' => Http::response([
                'status' => ['state' => 'pending'],
            ], 202),
        ]);

        $testable = Native::test(Search::class);
        $testable->set('results', [
            [
                'tmdb_id' => 603,
                'type' => 'movie',
                'name' => 'The Matrix',
                'year' => '1999',
                'poster_url' => 'https://image.tmdb.org/t/p/w342/poster.jpg',
                'title_id' => 42,
                'watched' => false,
                'status' => null,
                'on_list' => false,
                'followed' => false,
                'is_re_release' => false,
            ],
        ]);

        $testable->call('requestTitle', 0);

        Http::assertSent(function ($request) {
            return $request->url() === 'https://example.com/api/v1/titles/42/request';
        });

        // Check that status was updated
        $results = $testable->get('results');
        $this->assertEquals('pending', $results[0]['status']);
    }

    public function test_imports_and_requests_tmdb_only_result(): void
    {
        Http::fake([
            'https://example.com/api/v1/discover/trending' => Http::response(['data' => []]),
            'https://example.com/api/v1/discover/new' => Http::response(['data' => []]),
            'https://example.com/api/v1/discover/new-episodes' => Http::response(['data' => []]),
            'https://example.com/api/v1/discover/recommendations' => Http::response(['data' => []]),
            'https://example.com/api/v1/titles/import' => Http::response([
                'id' => 123,
                'type' => 'movie',
                'tmdb_id' => 603,
                'name' => 'The Matrix',
            ], 201),
            'https://example.com/api/v1/titles/123/request' => Http::response([
                'status' => ['state' => 'pending'],
            ], 202),
        ]);

        $testable = Native::test(Search::class);
        $testable->set('results', [
            [
                'tmdb_id' => 603,
                'type' => 'movie',
                'name' => 'The Matrix',
                'year' => '1999',
                'poster_url' => 'https://image.tmdb.org/t/p/w342/poster.jpg',
                'title_id' => null,
                'watched' => false,
                'status' => null,
                'on_list' => false,
                'followed' => false,
                'is_re_release' => false,
            ],
        ]);

        $testable->call('requestTitle', 0);

        Http::assertSent(function ($request) {
            return $request->url() === 'https://example.com/api/v1/titles/import';
        });

        Http::assertSent(function ($request) {
            return $request->url() === 'https://example.com/api/v1/titles/123/request';
        });

        // Check that status was updated
        $results = $testable->get('results');
        $this->assertEquals('pending', $results[0]['status']);
    }

    public function test_handles_seerr_not_configured_error(): void
    {
        Http::fake([
            'https://example.com/api/v1/discover/trending' => Http::response(['data' => []]),
            'https://example.com/api/v1/discover/new' => Http::response(['data' => []]),
            'https://example.com/api/v1/discover/new-episodes' => Http::response(['data' => []]),
            'https://example.com/api/v1/discover/recommendations' => Http::response(['data' => []]),
            'https://example.com/api/v1/titles/42/request' => Http::response([
                'message' => 'The given data was invalid.',
                'errors' => [
                    'title' => ['Could not send the request. Check the Seerr connection in Settings.'],
                ],
            ], 422),
        ]);

        $testable = Native::test(Search::class);
        $testable->set('results', [
            [
                'tmdb_id' => 603,
                'type' => 'movie',
                'name' => 'The Matrix',
                'year' => '1999',
                'poster_url' => 'https://image.tmdb.org/t/p/w342/poster.jpg',
                'title_id' => 42,
                'watched' => false,
                'status' => null,
                'on_list' => false,
                'followed' => false,
                'is_re_release' => false,
            ],
        ]);

        $testable->call('requestTitle', 0);

        $testable->assertSet('error', 'Could not send the request. Check the Seerr connection in Settings.');
    }

    public function test_does_not_show_request_button_for_watched_titles(): void
    {
        $component = new Search;

        $result = [
            'tmdb_id' => 603,
            'type' => 'movie',
            'name' => 'The Matrix',
            'watched' => true,
            'status' => null,
        ];

        $canRequest = $component->canRequest($result);

        $this->assertFalse($canRequest);
    }

    public function test_does_not_show_request_button_for_owned_titles(): void
    {
        $component = new Search;

        $result = [
            'tmdb_id' => 603,
            'type' => 'movie',
            'name' => 'The Matrix',
            'watched' => false,
            'status' => 'owned',
        ];

        $canRequest = $component->canRequest($result);

        $this->assertFalse($canRequest);
    }

    public function test_redirects_to_sign_in_on_401(): void
    {
        Http::fake([
            'https://example.com/api/v1/discover/trending' => Http::response(['data' => []]),
            'https://example.com/api/v1/discover/new' => Http::response(['data' => []]),
            'https://example.com/api/v1/discover/new-episodes' => Http::response(['data' => []]),
            'https://example.com/api/v1/discover/recommendations' => Http::response(['data' => []]),
            'https://example.com/api/v1/search*' => Http::response(null, 401),
        ]);

        $testable = Native::test(Search::class)
            ->set('query', 'matrix'); // updatedQuery() will call search() automatically

        $testable->assertReplacedWith('/sign-in');

        // Token should be cleared (set to null)
        $this->assertNull($this->storedData['api_token'] ?? null);
    }
}
