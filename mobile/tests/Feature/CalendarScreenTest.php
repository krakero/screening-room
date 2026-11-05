<?php

namespace Tests\Feature;

use App\NativeComponents\Calendar;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Native\Mobile\Testing\Native;
use Tests\TestCase;

class CalendarScreenTest extends TestCase
{
    private array $storedData = [];

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-18 12:00:00');
        $this->storedData = [];
        $bridge = Native::fakeBridge();

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
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_loads_calendar_and_catch_up_on_mount(): void
    {

        $this->storedData = [
            'server_url' => 'https://example.com',
            'api_token' => 'valid-token',
        ];

        $today = now()->toDateString();
        $tomorrow = now()->addDay()->toDateString();
        $yesterday = now()->subDays(2)->toDateString();

        Http::fake([
            'https://example.com/api/v1/calendar/catch-up' => Http::response([
                'data' => [
                    [
                        'type' => 'episode',
                        'date' => $yesterday,
                        'watched' => false,
                        'title' => [
                            'id' => 1,
                            'type' => 'show',
                            'name' => 'Test Show',
                            'poster_url' => 'https://example.com/poster.jpg',
                        ],
                        'episode' => [
                            'id' => 10,
                            'season_number' => 1,
                            'episode_number' => 3,
                            'code' => 'S01E03',
                            'name' => 'Episode Three',
                        ],
                    ],
                ],
            ]),
            'https://example.com/api/v1/calendar?*' => Http::response([
                'data' => [
                    [
                        'type' => 'episode',
                        'date' => $today,
                        'watched' => false,
                        'title' => [
                            'id' => 1,
                            'type' => 'show',
                            'name' => 'Test Show',
                            'poster_url' => 'https://example.com/poster.jpg',
                        ],
                        'episode' => [
                            'id' => 11,
                            'season_number' => 1,
                            'episode_number' => 4,
                            'code' => 'S01E04',
                            'name' => 'Episode Four',
                        ],
                    ],
                    [
                        'type' => 'episode',
                        'date' => $tomorrow,
                        'watched' => false,
                        'title' => [
                            'id' => 2,
                            'type' => 'show',
                            'name' => 'Another Show',
                            'poster_url' => 'https://example.com/poster2.jpg',
                        ],
                        'episode' => [
                            'id' => 20,
                            'season_number' => 2,
                            'episode_number' => 1,
                            'code' => 'S02E01',
                            'name' => 'Season Premiere',
                        ],
                    ],
                ],
            ]),
        ]);

        $testable = Native::test(Calendar::class);

        $testable->assertSet('loading', false);
        $testable->assertSet('loadingCatchUp', false);
        $testable->assertSet('error', '');
        $this->assertCount(1, $testable->get('catchUpEntries'));
        $this->assertCount(2, $testable->get('entries'));
        $testable->assertSee('Catch Up');
        $testable->assertSee('Today');
        $testable->assertSee('Test Show');
        $testable->assertSee('Another Show');
        $testable->assertSee('S01E03');
        $testable->assertSee('S01E04');
        $testable->assertSee('S02E01');

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/calendar/catch-up')
                && $request->hasHeader('Authorization', 'Bearer valid-token');
        });

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/calendar')
                && ! str_contains($request->url(), '/catch-up')
                && $request->hasHeader('Authorization', 'Bearer valid-token')
                && str_contains($request->url(), 'start=')
                && str_contains($request->url(), 'end=');
        });
    }

    public function test_shows_empty_state_when_no_entries(): void
    {
        $this->storedData = [
            'server_url' => 'https://example.com',
            'api_token' => 'valid-token',
        ];

        Http::fake([
            'https://example.com/api/v1/calendar/catch-up' => Http::response(['data' => []]),
            'https://example.com/api/v1/calendar?*' => Http::response(['data' => []]),
        ]);

        $testable = Native::test(Calendar::class);

        $testable->assertSet('loading', false);
        $testable->assertSee('No Upcoming Releases');
        $testable->assertSee('Follow some shows to see their upcoming episodes here');
    }

    public function test_shows_error_state_and_retries(): void
    {
        $this->storedData = [
            'server_url' => 'https://example.com',
            'api_token' => 'valid-token',
        ];

        Http::fake([
            'https://example.com/api/v1/calendar/catch-up' => Http::response([], 500),
            'https://example.com/api/v1/calendar?*' => Http::sequence()
                ->push([], 500)
                ->push(['data' => []]),
        ]);

        $testable = Native::test(Calendar::class);

        $testable->assertSet('loading', false);
        $testable->assertSet('error', 'The server had a problem. Please try again.');
        $testable->assertSee('The server had a problem');
        $testable->assertSee('Retry');

        // Retry
        $testable->call('retry');

        $testable->assertSet('loading', false);
        $testable->assertSet('error', '');
        $testable->assertSee('No Upcoming Releases');
    }

    public function test_redirects_to_sign_in_on_unauthenticated(): void
    {
        $this->storedData = [
            'server_url' => 'https://example.com',
            'api_token' => 'invalid-token',
        ];

        Http::fake([
            'https://example.com/api/v1/calendar/catch-up' => Http::response([], 401),
            'https://example.com/api/v1/calendar?*' => Http::response([], 401),
        ]);

        $testable = Native::test(Calendar::class);

        $testable->assertReplacedWith('/sign-in');

        // Verify token was cleared
        $this->assertNull($this->storedData['api_token'] ?? null);
    }

    public function test_pull_to_refresh_reloads_data(): void
    {
        $this->storedData = [
            'server_url' => 'https://example.com',
            'api_token' => 'valid-token',
        ];

        $today = now()->toDateString();
        $yesterday = now()->subDay()->toDateString();

        Http::fake([
            'https://example.com/api/v1/calendar/catch-up' => Http::sequence()
                ->push(['data' => []])
                ->push(['data' => [
                    [
                        'type' => 'episode',
                        'date' => $yesterday,
                        'watched' => false,
                        'title' => [
                            'id' => 1,
                            'type' => 'show',
                            'name' => 'New Show',
                            'poster_url' => 'https://example.com/poster.jpg',
                        ],
                        'episode' => [
                            'id' => 10,
                            'season_number' => 1,
                            'episode_number' => 1,
                            'code' => 'S01E01',
                            'name' => 'Pilot',
                        ],
                    ],
                ]]),
            'https://example.com/api/v1/calendar?*' => Http::sequence()
                ->push(['data' => []])
                ->push(['data' => [
                    [
                        'type' => 'episode',
                        'date' => $today,
                        'watched' => false,
                        'title' => [
                            'id' => 1,
                            'type' => 'show',
                            'name' => 'New Show',
                            'poster_url' => 'https://example.com/poster.jpg',
                        ],
                        'episode' => [
                            'id' => 11,
                            'season_number' => 1,
                            'episode_number' => 2,
                            'code' => 'S01E02',
                            'name' => 'Episode Two',
                        ],
                    ],
                ]]),
        ]);

        $testable = Native::test(Calendar::class);

        $testable->assertSee('No Upcoming Releases');

        // Pull to refresh
        $testable->call('refresh');

        $testable->assertSet('loading', false);
        $testable->assertSee('New Show');
        $testable->assertSee('Catch Up');
    }

    public function test_loads_more_entries_on_scroll(): void
    {
        $this->storedData = [
            'server_url' => 'https://example.com',
            'api_token' => 'valid-token',
        ];

        $today = now()->toDateString();
        $futureDate = now()->addDays(35)->toDateString();

        Http::fake([
            'https://example.com/api/v1/calendar/catch-up' => Http::response(['data' => []]),
            'https://example.com/api/v1/calendar?*' => Http::sequence()
                ->push(['data' => [
                    [
                        'type' => 'episode',
                        'date' => $today,
                        'watched' => false,
                        'title' => [
                            'id' => 1,
                            'type' => 'show',
                            'name' => 'First Batch',
                            'poster_url' => 'https://example.com/poster.jpg',
                        ],
                        'episode' => [
                            'id' => 1,
                            'season_number' => 1,
                            'episode_number' => 1,
                            'code' => 'S01E01',
                            'name' => 'Episode One',
                        ],
                    ],
                ]])
                ->push(['data' => [
                    [
                        'type' => 'episode',
                        'date' => $futureDate,
                        'watched' => false,
                        'title' => [
                            'id' => 2,
                            'type' => 'show',
                            'name' => 'Second Batch',
                            'poster_url' => 'https://example.com/poster2.jpg',
                        ],
                        'episode' => [
                            'id' => 2,
                            'season_number' => 1,
                            'episode_number' => 1,
                            'code' => 'S01E01',
                            'name' => 'Another Episode',
                        ],
                    ],
                ]]),
        ]);

        $testable = Native::test(Calendar::class);

        $testable->assertSet('loading', false);
        $testable->assertSee('First Batch');
        $this->assertCount(1, $testable->get('entries'));

        // Load more
        $testable->call('loadMore');

        $testable->assertSet('loadingMore', false);
        $this->assertCount(2, $testable->get('entries'));
        $testable->assertSee('First Batch');
        $testable->assertSee('Second Batch');
    }

    public function test_navigates_to_title_detail_on_tap(): void
    {
        $this->storedData = [
            'server_url' => 'https://example.com',
            'api_token' => 'valid-token',
        ];

        Http::fake([
            'https://example.com/api/v1/calendar/catch-up' => Http::response(['data' => []]),
            'https://example.com/api/v1/calendar?*' => Http::response([
                'data' => [
                    [
                        'type' => 'episode',
                        'date' => '2026-10-18',
                        'watched' => false,
                        'title' => [
                            'id' => 42,
                            'type' => 'show',
                            'name' => 'Test Show',
                            'poster_url' => 'https://example.com/poster.jpg',
                        ],
                        'episode' => [
                            'id' => 10,
                            'season_number' => 1,
                            'episode_number' => 1,
                            'code' => 'S01E01',
                            'name' => 'Pilot',
                        ],
                    ],
                ],
            ]),
        ]);

        $testable = Native::test(Calendar::class);

        $testable->call('openTitle', 42);

        $testable->assertNavigatedTo('/titles/42');
    }

    public function test_groups_entries_by_day_with_formatted_headers(): void
    {
        $this->storedData = [
            'server_url' => 'https://example.com',
            'api_token' => 'valid-token',
        ];

        $today = now()->toDateString();
        $tomorrow = now()->addDay()->toDateString();

        Http::fake([
            'https://example.com/api/v1/calendar/catch-up' => Http::response(['data' => []]),
            'https://example.com/api/v1/calendar?*' => Http::response([
                'data' => [
                    [
                        'type' => 'episode',
                        'date' => $today,
                        'watched' => false,
                        'title' => [
                            'id' => 1,
                            'type' => 'show',
                            'name' => 'Today Show',
                            'poster_url' => 'https://example.com/poster.jpg',
                        ],
                        'episode' => [
                            'id' => 1,
                            'season_number' => 1,
                            'episode_number' => 1,
                            'code' => 'S01E01',
                            'name' => 'Episode',
                        ],
                    ],
                    [
                        'type' => 'episode',
                        'date' => $tomorrow,
                        'watched' => false,
                        'title' => [
                            'id' => 2,
                            'type' => 'show',
                            'name' => 'Tomorrow Show',
                            'poster_url' => 'https://example.com/poster2.jpg',
                        ],
                        'episode' => [
                            'id' => 2,
                            'season_number' => 1,
                            'episode_number' => 1,
                            'code' => 'S01E01',
                            'name' => 'Episode',
                        ],
                    ],
                ],
            ]),
        ]);

        $testable = Native::test(Calendar::class);

        $testable->assertSee('Today');
        $testable->assertSee('Tomorrow');
        $testable->assertSee('Today Show');
        $testable->assertSee('Tomorrow Show');

        $grouped = $testable->get('groupedEntries');
        $this->assertArrayHasKey('Today', $grouped);
        $this->assertArrayHasKey('Tomorrow', $grouped);
        $this->assertCount(1, $grouped['Today']['entries']);
        $this->assertCount(1, $grouped['Tomorrow']['entries']);
        $this->assertInstanceOf(Carbon::class, $grouped['Today']['date']);
        $this->assertInstanceOf(Carbon::class, $grouped['Tomorrow']['date']);
    }

    public function test_groups_consecutive_episodes_on_same_day(): void
    {
        $this->storedData = [
            'server_url' => 'https://example.com',
            'api_token' => 'valid-token',
        ];

        $today = now()->toDateString();

        Http::fake([
            'https://example.com/api/v1/calendar/catch-up' => Http::response(['data' => []]),
            'https://example.com/api/v1/calendar?*' => Http::response([
                'data' => [
                    [
                        'type' => 'episode',
                        'date' => $today,
                        'watched' => true,
                        'title' => [
                            'id' => 1,
                            'type' => 'show',
                            'name' => 'Binge Show',
                            'poster_url' => 'https://example.com/poster.jpg',
                        ],
                        'episode' => [
                            'id' => 1,
                            'season_number' => 2,
                            'episode_number' => 1,
                            'code' => 'S02E01',
                            'name' => 'Episode One',
                        ],
                    ],
                    [
                        'type' => 'episode',
                        'date' => $today,
                        'watched' => true,
                        'title' => [
                            'id' => 1,
                            'type' => 'show',
                            'name' => 'Binge Show',
                            'poster_url' => 'https://example.com/poster.jpg',
                        ],
                        'episode' => [
                            'id' => 2,
                            'season_number' => 2,
                            'episode_number' => 2,
                            'code' => 'S02E02',
                            'name' => 'Episode Two',
                        ],
                    ],
                    [
                        'type' => 'episode',
                        'date' => $today,
                        'watched' => true,
                        'title' => [
                            'id' => 1,
                            'type' => 'show',
                            'name' => 'Binge Show',
                            'poster_url' => 'https://example.com/poster.jpg',
                        ],
                        'episode' => [
                            'id' => 3,
                            'season_number' => 2,
                            'episode_number' => 3,
                            'code' => 'S02E03',
                            'name' => 'Episode Three',
                        ],
                    ],
                ],
            ]),
        ]);

        $testable = Native::test(Calendar::class);

        $grouped = $testable->get('groupedEntries');
        $this->assertArrayHasKey('Today', $grouped);
        $this->assertCount(1, $grouped['Today']['entries'], 'Expected 3 consecutive episodes to be collapsed into 1 group');

        $collapsedEntry = $grouped['Today']['entries'][0];
        $this->assertTrue($collapsedEntry['_grouped'] ?? false);
        $this->assertEquals(3, $collapsedEntry['_episodeCount']);
        $this->assertEquals('S02E01', $collapsedEntry['_firstEpisode']['code']);
        $this->assertEquals('S02E03', $collapsedEntry['_lastEpisode']['code']);
        $this->assertTrue($collapsedEntry['_allWatched']);
    }
}
