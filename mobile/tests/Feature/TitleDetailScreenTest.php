<?php

namespace Tests\Feature;

use App\NativeComponents\TitleDetail;
use Illuminate\Support\Facades\Http;
use Native\Mobile\Testing\Native;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class TitleDetailScreenTest extends TestCase
{
    private array $storedData = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->storedData = ['server_url' => 'https://example.com', 'auth_token' => 'test-token'];

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
    }

    #[Test]
    public function it_displays_movie_details(): void
    {
        Http::fake([
            'https://example.com/api/v1/titles/1' => Http::response([
                'id' => 1,
                'type' => 'movie',
                'name' => 'Test Movie',
                'year_range' => '2024',
                'runtime' => 120,
                'genres' => ['Action', 'Drama'],
                'overview' => 'A test movie overview',
                'poster_url' => 'https://image.tmdb.org/poster.jpg',
                'backdrop_url' => 'https://image.tmdb.org/backdrop.jpg',
                'backdrop_url_w780' => 'https://image.tmdb.org/backdrop_w780.jpg',
                'movie_plays' => [],
                'request_status' => null,
                'plex_play_url' => null,
            ]),
        ]);

        $testable = Native::test(TitleDetail::class, ['id' => 1]);

        $testable->assertSet('loading', false);
        $testable->assertSee('Test Movie');
        $testable->assertSee('2024');
        $testable->assertSee('120 min');
        $testable->assertSee('Action');
        $testable->assertSee('A test movie overview');
        $testable->assertSee('Mark Watched');
    }

    #[Test]
    public function it_displays_show_details(): void
    {
        Http::fake([
            'https://example.com/api/v1/titles/2' => Http::response([
                'id' => 2,
                'type' => 'show',
                'name' => 'Test Show',
                'year_range' => '2020-2024',
                'genres' => ['Comedy'],
                'overview' => 'A test show overview',
                'poster_url' => 'https://image.tmdb.org/poster.jpg',
                'backdrop_url' => 'https://image.tmdb.org/backdrop.jpg',
                'follow' => null,
                'request_status' => null,
                'plex_play_url' => null,
                'seasons' => [
                    ['id' => 1, 'season_number' => 1, 'name' => 'Season 1', 'episode_count' => 10],
                ],
            ]),
            'https://example.com/api/v1/titles/2/seasons/1' => Http::response([
                'id' => 1,
                'season_number' => 1,
                'name' => 'Season 1',
                'aired_unwatched_count' => 5,
                'episodes' => [
                    ['id' => 1, 'episode_number' => 1, 'name' => 'Pilot', 'air_date' => '2020-01-01', 'watched' => false],
                ],
            ]),
        ]);

        $testable = Native::test(TitleDetail::class, ['id' => 2]);

        $testable->assertSet('loading', false);
        $testable->assertSee('Test Show');
        $testable->assertSee('1 Season · Comedy');
        $testable->assertSee('Follow');
        $testable->assertSee('Season 1');
        $testable->assertSee('E1: Pilot');
    }

    #[Test]
    public function it_marks_movie_watched(): void
    {
        Http::fake([
            'https://example.com/api/v1/titles/1' => Http::sequence()
                ->push([
                    'id' => 1,
                    'type' => 'movie',
                    'name' => 'Test Movie',
                    'movie_plays' => [],
                    'request_status' => null,
                ])
                ->push([
                    'id' => 1,
                    'type' => 'movie',
                    'name' => 'Test Movie',
                    'movie_plays' => [['id' => 1, 'watched_at' => '2024-01-01T00:00:00Z']],
                    'request_status' => null,
                ]),
            'https://example.com/api/v1/titles/1/watch' => Http::response(['play' => ['id' => 1]], 201),
        ]);

        $testable = Native::test(TitleDetail::class, ['id' => 1]);

        $testable->assertSee('Mark Watched');
        $testable->call('toggleMovieWatched');

        Http::assertSent(fn ($request) => $request->url() === 'https://example.com/api/v1/titles/1/watch' &&
            $request->method() === 'POST'
        );
    }

    #[Test]
    public function it_unmarks_movie_watched(): void
    {
        Http::fake([
            'https://example.com/api/v1/titles/1' => Http::sequence()
                ->push([
                    'id' => 1,
                    'type' => 'movie',
                    'name' => 'Test Movie',
                    'movie_plays' => [['id' => 123, 'watched_at' => '2024-01-01T00:00:00Z', 'source' => 'manual']],
                    'request_status' => null,
                ])
                ->push([
                    'id' => 1,
                    'type' => 'movie',
                    'name' => 'Test Movie',
                    'movie_plays' => [],
                    'request_status' => null,
                ]),
            'https://example.com/api/v1/plays/123' => Http::response(null, 204),
        ]);

        $testable = Native::test(TitleDetail::class, ['id' => 1]);

        $testable->assertSee('Mark Unwatched');
        $testable->call('toggleMovieWatched');

        Http::assertSent(fn ($request) => $request->url() === 'https://example.com/api/v1/plays/123' &&
            $request->method() === 'DELETE'
        );
    }

    #[Test]
    public function it_rolls_back_movie_watch_on_error(): void
    {
        Http::fake([
            'https://example.com/api/v1/titles/1' => Http::response([
                'id' => 1,
                'type' => 'movie',
                'name' => 'Test Movie',
                'movie_plays' => [],
                'request_status' => null,
            ]),
            'https://example.com/api/v1/titles/1/watch' => Http::response(null, 500),
        ]);

        $testable = Native::test(TitleDetail::class, ['id' => 1]);

        $testable->call('toggleMovieWatched');

        $testable->assertSet('optimisticMovieWatched', null);
        $testable->assertSet('error', 'Failed to update: The server had a problem. Please try again.');
    }

    #[Test]
    public function it_toggles_episode_watched_state(): void
    {
        Http::fake([
            'https://example.com/api/v1/titles/2' => Http::response([
                'id' => 2,
                'type' => 'show',
                'name' => 'Test Show',
                'seasons' => [['id' => 1, 'season_number' => 1, 'name' => 'Season 1']],
                'follow' => null,
            ]),
            'https://example.com/api/v1/titles/2/seasons/1' => Http::response([
                'id' => 1,
                'season_number' => 1,
                'name' => 'Season 1',
                'aired_unwatched_count' => 1,
                'episodes' => [
                    ['id' => 1, 'episode_number' => 1, 'name' => 'Pilot', 'watched' => false],
                ],
            ]),
            'https://example.com/api/v1/episodes/1/watch' => Http::response(['play' => ['id' => 1]], 201),
        ]);

        $testable = Native::test(TitleDetail::class, ['id' => 2]);

        $testable->call('toggleEpisodeWatched', 1);

        Http::assertSent(fn ($request) => $request->url() === 'https://example.com/api/v1/episodes/1/watch' &&
            $request->method() === 'POST'
        );

        // After successful API call, optimistic state is cleared and actual data is updated
        $this->assertArrayNotHasKey(1, $testable->get('optimisticWatched'));
        $this->assertTrue($testable->get('selectedSeason')['episodes'][0]['watched']);
    }

    #[Test]
    public function it_unwatches_episode(): void
    {
        Http::fake([
            'https://example.com/api/v1/titles/2' => Http::response([
                'id' => 2,
                'type' => 'show',
                'name' => 'Test Show',
                'seasons' => [['id' => 1, 'season_number' => 1, 'name' => 'Season 1']],
                'follow' => null,
            ]),
            'https://example.com/api/v1/titles/2/seasons/1' => Http::response([
                'id' => 1,
                'season_number' => 1,
                'name' => 'Season 1',
                'aired_unwatched_count' => 0,
                'episodes' => [
                    ['id' => 1, 'episode_number' => 1, 'name' => 'Pilot', 'watched' => true],
                ],
            ]),
            'https://example.com/api/v1/episodes/1/watch' => Http::response(null, 204),
        ]);

        $testable = Native::test(TitleDetail::class, ['id' => 2]);

        $testable->call('toggleEpisodeWatched', 1);

        Http::assertSent(fn ($request) => $request->url() === 'https://example.com/api/v1/episodes/1/watch' &&
            $request->method() === 'DELETE'
        );
    }

    #[Test]
    public function it_changes_seasons(): void
    {
        Http::fake([
            'https://example.com/api/v1/titles/2' => Http::response([
                'id' => 2,
                'type' => 'show',
                'name' => 'Test Show',
                'seasons' => [
                    ['id' => 1, 'season_number' => 1, 'name' => 'Season 1'],
                    ['id' => 2, 'season_number' => 2, 'name' => 'Season 2'],
                ],
                'follow' => null,
            ]),
            'https://example.com/api/v1/titles/2/seasons/1' => Http::response([
                'id' => 1,
                'season_number' => 1,
                'name' => 'Season 1',
                'episodes' => [],
                'aired_unwatched_count' => 0,
            ]),
            'https://example.com/api/v1/titles/2/seasons/2' => Http::response([
                'id' => 2,
                'season_number' => 2,
                'name' => 'Season 2',
                'episodes' => [
                    ['id' => 10, 'episode_number' => 1, 'name' => 'S2E1', 'watched' => false],
                ],
                'aired_unwatched_count' => 1,
            ]),
        ]);

        $testable = Native::test(TitleDetail::class, ['id' => 2]);

        $testable->assertSet('selectedSeasonNumber', 1);
        $testable->call('fetchSeason', 2);

        $testable->assertSet('selectedSeasonNumber', 2);
        $testable->assertSee('S2E1');
    }

    #[Test]
    public function it_previews_and_marks_season_watched(): void
    {
        Http::fake([
            'https://example.com/api/v1/titles/2' => Http::sequence()
                ->push([
                    'id' => 2,
                    'type' => 'show',
                    'name' => 'Test Show',
                    'seasons' => [['id' => 1, 'season_number' => 1, 'name' => 'Season 1']],
                    'follow' => null,
                ])
                ->push([
                    'id' => 2,
                    'type' => 'show',
                    'name' => 'Test Show',
                    'seasons' => [['id' => 1, 'season_number' => 1, 'name' => 'Season 1']],
                    'follow' => null,
                ]),
            'https://example.com/api/v1/titles/2/seasons/1' => Http::sequence()
                ->push([
                    'id' => 1,
                    'season_number' => 1,
                    'name' => 'Season 1',
                    'aired_unwatched_count' => 3,
                    'episodes' => [],
                ])
                ->push([
                    'id' => 1,
                    'season_number' => 1,
                    'name' => 'Season 1',
                    'aired_unwatched_count' => 0,
                    'episodes' => [],
                ]),
            'https://example.com/api/v1/seasons/1/watch/preview' => Http::response(['aired_unwatched_count' => 3]),
            'https://example.com/api/v1/seasons/1/watch' => Http::response(['marked_count' => 3]),
        ]);

        $testable = Native::test(TitleDetail::class, ['id' => 2]);

        $testable->call('showMarkSeasonWatchedConfirm');
        $testable->assertSet('showSeasonConfirm', true);
        $testable->assertSet('seasonConfirmCount', 3);

        $testable->call('confirmMarkSeasonWatched');

        Http::assertSent(fn ($request) => $request->url() === 'https://example.com/api/v1/seasons/1/watch' &&
            $request->method() === 'POST'
        );
    }

    #[Test]
    public function it_cancels_mark_season_watched(): void
    {
        Http::fake([
            'https://example.com/api/v1/titles/2' => Http::response([
                'id' => 2,
                'type' => 'show',
                'name' => 'Test Show',
                'seasons' => [['id' => 1, 'season_number' => 1, 'name' => 'Season 1']],
                'follow' => null,
            ]),
            'https://example.com/api/v1/titles/2/seasons/1' => Http::response([
                'id' => 1,
                'season_number' => 1,
                'name' => 'Season 1',
                'aired_unwatched_count' => 3,
                'episodes' => [],
            ]),
            'https://example.com/api/v1/seasons/1/watch/preview' => Http::response(['aired_unwatched_count' => 3]),
        ]);

        $testable = Native::test(TitleDetail::class, ['id' => 2]);

        $testable->call('showMarkSeasonWatchedConfirm');
        $testable->assertSet('showSeasonConfirm', true);

        $testable->call('cancelMarkSeasonWatched');
        $testable->assertSet('showSeasonConfirm', false);
    }

    #[Test]
    public function it_follows_a_show(): void
    {
        Http::fake([
            'https://example.com/api/v1/titles/2' => Http::sequence()
                ->push([
                    'id' => 2,
                    'type' => 'show',
                    'name' => 'Test Show',
                    'seasons' => [['id' => 1, 'season_number' => 1]],
                    'follow' => null,
                ])
                ->push([
                    'id' => 2,
                    'type' => 'show',
                    'name' => 'Test Show',
                    'seasons' => [['id' => 1, 'season_number' => 1]],
                    'follow' => ['id' => 1, 'state' => 'watching'],
                ]),
            'https://example.com/api/v1/titles/2/seasons/1' => Http::response([
                'id' => 1,
                'season_number' => 1,
                'name' => 'Season 1',
                'episodes' => [],
                'aired_unwatched_count' => 0,
            ]),
            'https://example.com/api/v1/titles/2/follow' => Http::response(['follow' => ['id' => 1]], 201),
        ]);

        $testable = Native::test(TitleDetail::class, ['id' => 2]);

        $testable->assertSee('Follow');
        $testable->call('followAction');

        Http::assertSent(fn ($request) => $request->url() === 'https://example.com/api/v1/titles/2/follow' &&
            $request->method() === 'POST'
        );
    }

    #[Test]
    public function it_pauses_a_following_show(): void
    {
        Http::fake([
            'https://example.com/api/v1/titles/2' => Http::sequence()
                ->push([
                    'id' => 2,
                    'type' => 'show',
                    'name' => 'Test Show',
                    'seasons' => [['id' => 1, 'season_number' => 1]],
                    'follow' => ['id' => 5, 'state' => 'watching'],
                ])
                ->push([
                    'id' => 2,
                    'type' => 'show',
                    'name' => 'Test Show',
                    'seasons' => [['id' => 1, 'season_number' => 1]],
                    'follow' => ['id' => 5, 'state' => 'paused'],
                ]),
            'https://example.com/api/v1/titles/2/seasons/1' => Http::response([
                'id' => 1,
                'season_number' => 1,
                'name' => 'Season 1',
                'episodes' => [],
                'aired_unwatched_count' => 0,
            ]),
            'https://example.com/api/v1/follows/5/pause' => Http::response(['follow' => ['id' => 5, 'state' => 'paused']]),
        ]);

        $testable = Native::test(TitleDetail::class, ['id' => 2]);

        $testable->assertSee('Pause');
        $testable->call('followAction');

        Http::assertSent(fn ($request) => $request->url() === 'https://example.com/api/v1/follows/5/pause' &&
            $request->method() === 'POST'
        );
    }

    #[Test]
    public function it_resumes_a_paused_show(): void
    {
        Http::fake([
            'https://example.com/api/v1/titles/2' => Http::sequence()
                ->push([
                    'id' => 2,
                    'type' => 'show',
                    'name' => 'Test Show',
                    'seasons' => [['id' => 1, 'season_number' => 1]],
                    'follow' => ['id' => 7, 'state' => 'paused'],
                ])
                ->push([
                    'id' => 2,
                    'type' => 'show',
                    'name' => 'Test Show',
                    'seasons' => [['id' => 1, 'season_number' => 1]],
                    'follow' => ['id' => 7, 'state' => 'watching'],
                ]),
            'https://example.com/api/v1/titles/2/seasons/1' => Http::response([
                'id' => 1,
                'season_number' => 1,
                'name' => 'Season 1',
                'episodes' => [],
                'aired_unwatched_count' => 0,
            ]),
            'https://example.com/api/v1/follows/7/resume' => Http::response(['follow' => ['id' => 7, 'state' => 'watching']]),
        ]);

        $testable = Native::test(TitleDetail::class, ['id' => 2]);

        $testable->assertSee('Resume');
        $testable->call('followAction');

        Http::assertSent(fn ($request) => $request->url() === 'https://example.com/api/v1/follows/7/resume' &&
            $request->method() === 'POST'
        );
    }

    #[Test]
    public function it_rolls_back_episode_watch_on_error(): void
    {
        Http::fake([
            'https://example.com/api/v1/titles/2' => Http::response([
                'id' => 2,
                'type' => 'show',
                'name' => 'Test Show',
                'seasons' => [['id' => 1, 'season_number' => 1, 'name' => 'Season 1']],
                'follow' => null,
            ]),
            'https://example.com/api/v1/titles/2/seasons/1' => Http::response([
                'id' => 1,
                'season_number' => 1,
                'name' => 'Season 1',
                'aired_unwatched_count' => 1,
                'episodes' => [
                    ['id' => 10, 'episode_number' => 1, 'name' => 'Pilot', 'watched' => false],
                ],
            ]),
            'https://example.com/api/v1/episodes/10/watch' => Http::response(null, 500),
        ]);

        $testable = Native::test(TitleDetail::class, ['id' => 2]);

        $testable->call('toggleEpisodeWatched', 10);

        $this->assertFalse(isset($testable->get('optimisticWatched')[10]));
        $testable->assertSee('Failed to update');
    }

    #[Test]
    public function it_requests_unavailable_title(): void
    {
        Http::fake([
            'https://example.com/api/v1/titles/1' => Http::sequence()
                ->push([
                    'id' => 1,
                    'type' => 'movie',
                    'name' => 'Test Movie',
                    'request_status' => null,
                    'movie_plays' => [],
                ])
                ->push([
                    'id' => 1,
                    'type' => 'movie',
                    'name' => 'Test Movie',
                    'request_status' => ['state' => 'pending'],
                    'movie_plays' => [],
                ]),
            'https://example.com/api/v1/titles/1/request' => Http::response(['status' => ['state' => 'pending']], 202),
        ]);

        $testable = Native::test(TitleDetail::class, ['id' => 1]);

        $testable->call('requestTitle');

        Http::assertSent(fn ($request) => $request->url() === 'https://example.com/api/v1/titles/1/request' &&
            $request->method() === 'POST'
        );
    }

    #[Test]
    public function it_shows_error_state_with_retry(): void
    {
        Http::fake([
            'https://example.com/api/v1/titles/1' => Http::sequence()
                ->push(null, 500)
                ->push(['id' => 1, 'type' => 'movie', 'name' => 'Test Movie', 'movie_plays' => []]),
        ]);

        $testable = Native::test(TitleDetail::class, ['id' => 1]);

        $testable->assertSet('loading', false);
        $testable->assertSet('error', 'The server had a problem. Please try again.');
        $testable->assertSee('Retry');

        $testable->call('retry');
        $testable->assertSet('error', '');
        $testable->assertSee('Test Movie');
    }

    #[Test]
    public function it_redirects_to_sign_in_on_401(): void
    {
        Http::fake([
            'https://example.com/api/v1/titles/1' => Http::response(null, 401),
        ]);

        $testable = Native::test(TitleDetail::class, ['id' => 1]);

        $testable->assertReplacedWith('/sign-in');
    }

    #[Test]
    public function it_handles_plex_availability(): void
    {
        Http::fake([
            'https://example.com/api/v1/titles/1' => Http::response([
                'id' => 1,
                'type' => 'movie',
                'name' => 'Test Movie',
                'plex_play_url' => 'plex://play/1234',
                'movie_plays' => [],
            ]),
        ]);

        $testable = Native::test(TitleDetail::class, ['id' => 1]);

        $testable->assertSee('Available on Plex');
    }
}
