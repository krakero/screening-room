<?php

namespace Tests\Feature;

use App\NativeComponents\UpNext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Native\Mobile\Testing\Native;
use Tests\TestCase;

class UpNextScreenTest extends TestCase
{
    use RefreshDatabase;

    private array $storedData = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->storedData = [
            'server_url' => 'https://example.com',
            'api_token' => 'test-token',
        ];
        $bridge = Native::fakeBridge();

        $bridge->respondTo('SecureStorage.Get', function ($params) {
            $key = $params['key'];

            if (isset($this->storedData[$key])) {
                return ['status' => 'Found', 'value' => $this->storedData[$key]];
            }

            return ['status' => 'NotFound', 'value' => null];
        });

        $bridge->respondTo('SecureStorage.Set', function ($params) {
            $this->storedData[$params['key']] = $params['value'];

            return ['success' => true];
        });

        $bridge->respondTo('SecureStorage.Delete', function ($params) {
            unset($this->storedData[$params['key']]);

            return ['success' => true];
        });
    }

    public function test_fetches_data_on_mount(): void
    {
        Http::fake([
            'https://example.com/api/v1/up-next' => Http::response([
                'greeting' => 'Good morning',
                'continue_watching' => [],
                'airing_this_week' => [],
                'recently_added' => [],
            ]),
        ]);

        $testable = Native::test(UpNext::class);

        // After mount completes, loading should be false and data fetched
        $testable->assertSet('loading', false);
        Http::assertSent(function ($request) {
            return $request->url() === 'https://example.com/api/v1/up-next' &&
                   $request->method() === 'GET';
        });
    }

    public function test_displays_empty_state_when_no_data(): void
    {
        Http::fake([
            'https://example.com/api/v1/up-next' => Http::response([
                'greeting' => 'Good morning',
                'continue_watching' => [],
                'airing_this_week' => [],
                'recently_added' => [],
            ]),
        ]);

        $testable = Native::test(UpNext::class);

        $testable->assertSet('loading', false);
        $testable->assertSet('continueWatching', []);
        $testable->assertSet('airingThisWeek', []);
        $testable->assertSet('recentlyAdded', []);
    }

    public function test_displays_continue_watching_section(): void
    {
        Http::fake([
            'https://example.com/api/v1/up-next' => Http::response([
                'greeting' => 'Good afternoon',
                'continue_watching' => [
                    [
                        'title' => [
                            'id' => 1,
                            'type' => 'show',
                            'name' => 'The Office',
                            'poster_url' => 'https://image.tmdb.org/t/p/w342/poster.jpg',
                        ],
                        'next_episode' => [
                            'id' => 10,
                            'season_number' => 2,
                            'episode_number' => 5,
                            'code' => 'S02E05',
                            'name' => 'Halloween',
                            'air_date' => '2005-10-18',
                            'has_aired' => true,
                        ],
                    ],
                ],
                'airing_this_week' => [],
                'recently_added' => [],
            ]),
        ]);

        $testable = Native::test(UpNext::class);

        $testable->assertSet('loading', false);
        $testable->assertSet('greeting', 'Good afternoon');
        $this->assertCount(1, $testable->instance()->continueWatching);
        $this->assertEquals('The Office', $testable->instance()->continueWatching[0]['title']['name']);
        $this->assertEquals('S02E05', $testable->instance()->continueWatching[0]['next_episode']['code']);
    }

    public function test_displays_airing_this_week_section(): void
    {
        Http::fake([
            'https://example.com/api/v1/up-next' => Http::response([
                'greeting' => 'Good evening',
                'continue_watching' => [],
                'airing_this_week' => [
                    '2026-10-18' => [
                        [
                            'id' => 20,
                            'season_number' => 1,
                            'episode_number' => 3,
                            'code' => 'S01E03',
                            'name' => 'New Episode',
                            'air_date' => '2026-10-18',
                            'has_aired' => false,
                            'can_mark_watched' => false,
                            'title' => [
                                'id' => 2,
                                'type' => 'show',
                                'name' => 'Breaking Bad',
                                'poster_url' => 'https://image.tmdb.org/t/p/w342/poster2.jpg',
                            ],
                        ],
                    ],
                ],
                'recently_added' => [],
            ]),
        ]);

        $testable = Native::test(UpNext::class);

        $testable->assertSet('loading', false);
        $airingThisWeek = $testable->instance()->airingThisWeek;
        $this->assertNotEmpty($airingThisWeek);
        $firstDayEpisodes = reset($airingThisWeek);
        $this->assertCount(1, $firstDayEpisodes);
        $this->assertEquals('Breaking Bad', $firstDayEpisodes[0]['title']['name']);
    }

    public function test_displays_recently_added_section(): void
    {
        Http::fake([
            'https://example.com/api/v1/up-next' => Http::response([
                'greeting' => 'Good morning',
                'continue_watching' => [],
                'airing_this_week' => [],
                'recently_added' => [
                    [
                        'id' => 3,
                        'type' => 'movie',
                        'name' => 'Inception',
                        'poster_url' => 'https://image.tmdb.org/t/p/w342/poster3.jpg',
                        'plex_available' => true,
                        'added_at' => '2026-11-01T10:00:00Z',
                        'episode_count' => 0,
                        'season_number' => null,
                        'episode_number' => null,
                        'label' => null,
                    ],
                ],
            ]),
        ]);

        $testable = Native::test(UpNext::class);

        $testable->assertSet('loading', false);
        $this->assertCount(1, $testable->instance()->recentlyAdded);
        $this->assertEquals('Inception', $testable->instance()->recentlyAdded[0]['name']);
    }

    public function test_recently_added_label_shown_when_present(): void
    {
        Http::fake([
            'https://example.com/api/v1/up-next' => Http::response([
                'greeting' => 'Good morning',
                'continue_watching' => [],
                'airing_this_week' => [],
                'recently_added' => [
                    [
                        'id' => 5,
                        'type' => 'show',
                        'name' => 'Severance',
                        'poster_url' => 'https://image.tmdb.org/t/p/w342/poster5.jpg',
                        'plex_available' => true,
                        'added_at' => '2026-11-02T12:00:00Z',
                        'episode_count' => 2,
                        'season_number' => null,
                        'episode_number' => null,
                        'label' => '2 new episodes',
                    ],
                    [
                        'id' => 6,
                        'type' => 'show',
                        'name' => 'The Bear',
                        'poster_url' => 'https://image.tmdb.org/t/p/w342/poster6.jpg',
                        'plex_available' => true,
                        'added_at' => '2026-11-01T08:00:00Z',
                        'episode_count' => 1,
                        'season_number' => 3,
                        'episode_number' => 7,
                        'label' => 'S3 E7',
                    ],
                ],
            ]),
        ]);

        $testable = Native::test(UpNext::class);

        $testable->assertSet('loading', false);
        $recentlyAdded = $testable->instance()->recentlyAdded;
        $this->assertCount(2, $recentlyAdded);
        $this->assertEquals('2 new episodes', $recentlyAdded[0]['label']);
        $this->assertEquals('S3 E7', $recentlyAdded[1]['label']);
    }

    public function test_recently_added_label_null_for_movie(): void
    {
        Http::fake([
            'https://example.com/api/v1/up-next' => Http::response([
                'greeting' => 'Good morning',
                'continue_watching' => [],
                'airing_this_week' => [],
                'recently_added' => [
                    [
                        'id' => 7,
                        'type' => 'movie',
                        'name' => 'Dune: Part Two',
                        'poster_url' => 'https://image.tmdb.org/t/p/w342/poster7.jpg',
                        'plex_available' => true,
                        'added_at' => '2026-11-03T14:00:00Z',
                        'episode_count' => 0,
                        'season_number' => null,
                        'episode_number' => null,
                        'label' => null,
                    ],
                ],
            ]),
        ]);

        $testable = Native::test(UpNext::class);

        $testable->assertSet('loading', false);
        $recentlyAdded = $testable->instance()->recentlyAdded;
        $this->assertCount(1, $recentlyAdded);
        $this->assertNull($recentlyAdded[0]['label']);
        $this->assertEquals('movie', $recentlyAdded[0]['type']);
    }

    public function test_mark_watched_calls_api_and_refreshes(): void
    {
        Http::fake([
            'https://example.com/api/v1/up-next' => Http::sequence()
                ->push([
                    'greeting' => 'Good morning',
                    'continue_watching' => [
                        [
                            'title' => ['id' => 1, 'type' => 'show', 'name' => 'The Office', 'poster_url' => 'https://example.com/poster.jpg'],
                            'next_episode' => ['id' => 10, 'season_number' => 2, 'episode_number' => 5, 'code' => 'S02E05', 'name' => 'Halloween', 'air_date' => '2005-10-18', 'has_aired' => true],
                        ],
                    ],
                    'airing_this_week' => [],
                    'recently_added' => [],
                ])
                ->push([
                    'greeting' => 'Good morning',
                    'continue_watching' => [
                        [
                            'title' => ['id' => 1, 'type' => 'show', 'name' => 'The Office', 'poster_url' => 'https://example.com/poster.jpg'],
                            'next_episode' => ['id' => 11, 'season_number' => 2, 'episode_number' => 6, 'code' => 'S02E06', 'name' => 'Next Episode', 'air_date' => '2005-10-25', 'has_aired' => true],
                        ],
                    ],
                    'airing_this_week' => [],
                    'recently_added' => [],
                ]),
            'https://example.com/api/v1/episodes/10/watch' => Http::response(['play' => ['id' => 100]], 201),
        ]);

        $testable = Native::test(UpNext::class);

        $testable->assertSet('loading', false);
        $this->assertEquals(10, $testable->instance()->continueWatching[0]['next_episode']['id']);

        $testable->call('markWatched', 10);

        Http::assertSent(function ($request) {
            return $request->url() === 'https://example.com/api/v1/episodes/10/watch' &&
                   $request->method() === 'POST' &&
                   $request->hasHeader('Authorization', 'Bearer test-token');
        });

        // After marking watched, data should refresh
        $testable->assertSet('loading', false);
        $this->assertEquals(11, $testable->instance()->continueWatching[0]['next_episode']['id']);
    }

    public function test_mark_watched_handles_error(): void
    {
        Http::fake([
            'https://example.com/api/v1/up-next' => Http::response([
                'greeting' => 'Good morning',
                'continue_watching' => [],
                'airing_this_week' => [],
                'recently_added' => [],
            ]),
            'https://example.com/api/v1/episodes/10/watch' => Http::response(['error' => 'Failed'], 500),
        ]);

        $testable = Native::test(UpNext::class);

        $testable->call('markWatched', 10);

        $testable->assertSet('error', 'The server had a problem. Please try again.');
    }

    public function test_open_title_navigates_to_title_detail(): void
    {
        Http::fake([
            'https://example.com/api/v1/up-next' => Http::response([
                'greeting' => 'Good morning',
                'continue_watching' => [],
                'airing_this_week' => [],
                'recently_added' => [],
            ]),
        ]);

        $testable = Native::test(UpNext::class);

        $testable->call('openTitle', 123);

        $testable->assertNavigatedTo('/titles/123');
    }

    public function test_retry_refetches_data(): void
    {
        Http::fake([
            'https://example.com/api/v1/up-next' => Http::sequence()
                ->push(['error' => 'Server error'], 500)
                ->push([
                    'greeting' => 'Good morning',
                    'continue_watching' => [],
                    'airing_this_week' => [],
                    'recently_added' => [],
                ]),
        ]);

        $testable = Native::test(UpNext::class);

        $testable->assertSet('error', 'The server had a problem. Please try again.');

        $testable->call('retry');

        $testable->assertSet('loading', false);
        $testable->assertSet('error', '');
    }

    public function test_401_redirects_to_sign_in(): void
    {
        Http::fake([
            'https://example.com/api/v1/up-next' => Http::response(['message' => 'Unauthenticated'], 401),
        ]);

        $testable = Native::test(UpNext::class);

        $testable->assertReplacedWith('/sign-in');
        $this->assertNull($this->storedData['api_token'] ?? null);
    }

    public function test_connection_error_shows_friendly_message(): void
    {
        Http::fake(fn () => throw new ConnectionException);

        $testable = Native::test(UpNext::class);

        $testable->assertSet('loading', false);
        $testable->assertSet('error', 'Unable to connect to server. Please check your connection.');
    }

    public function test_pull_to_refresh_refetches_data(): void
    {
        Http::fake([
            'https://example.com/api/v1/up-next' => Http::response([
                'greeting' => 'Good morning',
                'continue_watching' => [],
                'airing_this_week' => [],
                'recently_added' => [],
            ]),
        ]);

        $testable = Native::test(UpNext::class);

        // Initial load
        Http::assertSentCount(1);

        // Pull to refresh
        $testable->call('fetchData');

        Http::assertSentCount(2);
    }

    public function test_formats_air_date_for_aired_episodes(): void
    {
        Http::fake([
            'https://example.com/api/v1/up-next' => Http::response([
                'greeting' => 'Good morning',
                'continue_watching' => [],
                'airing_this_week' => [],
                'recently_added' => [],
            ]),
        ]);

        $testable = Native::test(UpNext::class);

        $formatted = $testable->instance()->formatAirDate('2022-07-11', true);
        $this->assertEquals('Aired Jul 11, 2022', $formatted);
    }

    public function test_formats_air_date_for_future_episodes_today(): void
    {
        Http::fake([
            'https://example.com/api/v1/up-next' => Http::response([
                'greeting' => 'Good morning',
                'continue_watching' => [],
                'airing_this_week' => [],
                'recently_added' => [],
            ]),
        ]);

        $testable = Native::test(UpNext::class);

        $today = now()->format('Y-m-d');
        $formatted = $testable->instance()->formatAirDate($today, false);
        $this->assertEquals('Airs Today', $formatted);
    }

    public function test_formats_air_date_for_future_episodes_tomorrow(): void
    {
        Http::fake([
            'https://example.com/api/v1/up-next' => Http::response([
                'greeting' => 'Good morning',
                'continue_watching' => [],
                'airing_this_week' => [],
                'recently_added' => [],
            ]),
        ]);

        $testable = Native::test(UpNext::class);

        $tomorrow = now()->addDay()->format('Y-m-d');
        $formatted = $testable->instance()->formatAirDate($tomorrow, false);
        $this->assertEquals('Airs Tomorrow', $formatted);
    }

    public function test_formats_air_date_for_future_episodes_weekday(): void
    {
        Http::fake([
            'https://example.com/api/v1/up-next' => Http::response([
                'greeting' => 'Good morning',
                'continue_watching' => [],
                'airing_this_week' => [],
                'recently_added' => [],
            ]),
        ]);

        $testable = Native::test(UpNext::class);

        $nextWeek = now()->addWeek()->format('Y-m-d');
        $formatted = $testable->instance()->formatAirDate($nextWeek, false);
        $this->assertStringStartsWith('Airs ', $formatted);
    }

    public function test_formats_day_label_today(): void
    {
        Http::fake([
            'https://example.com/api/v1/up-next' => Http::response([
                'greeting' => 'Good morning',
                'continue_watching' => [],
                'airing_this_week' => [],
                'recently_added' => [],
            ]),
        ]);

        $testable = Native::test(UpNext::class);

        $today = now()->format('Y-m-d');
        $formatted = $testable->instance()->formatDayLabel($today);
        $this->assertEquals('Today', $formatted);
    }

    public function test_formats_day_label_tomorrow(): void
    {
        Http::fake([
            'https://example.com/api/v1/up-next' => Http::response([
                'greeting' => 'Good morning',
                'continue_watching' => [],
                'airing_this_week' => [],
                'recently_added' => [],
            ]),
        ]);

        $testable = Native::test(UpNext::class);

        $tomorrow = now()->addDay()->format('Y-m-d');
        $formatted = $testable->instance()->formatDayLabel($tomorrow);
        $this->assertEquals('Tomorrow', $formatted);
    }

    public function test_formats_day_label_weekday(): void
    {
        Http::fake([
            'https://example.com/api/v1/up-next' => Http::response([
                'greeting' => 'Good morning',
                'continue_watching' => [],
                'airing_this_week' => [],
                'recently_added' => [],
            ]),
        ]);

        $testable = Native::test(UpNext::class);

        $nextWeek = now()->addWeek()->format('Y-m-d');
        $formatted = $testable->instance()->formatDayLabel($nextWeek);
        $this->assertContains($formatted, ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday']);
    }

    public function test_recently_added_with_plex_available(): void
    {
        Http::fake([
            'https://example.com/api/v1/up-next' => Http::response([
                'greeting' => 'Good morning',
                'continue_watching' => [],
                'airing_this_week' => [],
                'recently_added' => [
                    [
                        'id' => 3,
                        'type' => 'movie',
                        'name' => 'Inception',
                        'poster_url' => 'https://image.tmdb.org/t/p/w342/poster3.jpg',
                        'plex_available' => true,
                        'added_at' => '2026-11-01T10:00:00Z',
                        'episode_count' => 0,
                        'season_number' => null,
                        'episode_number' => null,
                        'label' => null,
                    ],
                    [
                        'id' => 4,
                        'type' => 'show',
                        'name' => 'The Office',
                        'poster_url' => 'https://image.tmdb.org/t/p/w342/poster4.jpg',
                        'plex_available' => true,
                        'added_at' => '2026-10-31T09:00:00Z',
                        'episode_count' => 3,
                        'season_number' => null,
                        'episode_number' => null,
                        'label' => '3 new episodes',
                    ],
                ],
            ]),
        ]);

        $testable = Native::test(UpNext::class);

        $testable->assertSet('loading', false);
        $this->assertCount(2, $testable->instance()->recentlyAdded);
        $this->assertTrue($testable->instance()->recentlyAdded[0]['plex_available']);
        $this->assertTrue($testable->instance()->recentlyAdded[1]['plex_available']);
    }
}
