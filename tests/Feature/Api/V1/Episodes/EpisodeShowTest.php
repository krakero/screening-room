<?php

use App\Actions\Tmdb\FetchEpisodeCredits;
use App\Enums\PlaySource;
use App\Jobs\ResolveEpisodeCredits;
use App\Models\Episode;
use App\Models\Play;
use App\Models\Season;
use App\Models\Title;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

function fakeApiEpisodeCredits(): void
{
    Http::fake([
        '*/tv/*/season/*/episode/*' => Http::response([
            'vote_average' => 8.4,
            'credits' => [
                'cast' => [
                    ['name' => 'Bryan Cranston', 'character' => 'Walter White', 'profile_path' => '/bc.jpg'],
                ],
                'crew' => [
                    ['name' => 'Vince Gilligan', 'job' => 'Director'],
                ],
                'guest_stars' => [['name' => 'Max Arciniega']],
            ],
        ]),
    ]);
}

/**
 * Seeds the cache as if `ResolveEpisodeCredits` already ran, so `show()` returns credits
 * inline instead of dispatching a job.
 */
function warmApiEpisodeCredits(Episode $episode): void
{
    Cache::put(FetchEpisodeCredits::cacheKey($episode), [
        'cast' => [
            ['name' => 'Bryan Cranston', 'character' => 'Walter White', 'profile_path' => '/bc.jpg'],
        ],
        'crew' => [
            ['name' => 'Vince Gilligan', 'job' => 'Director'],
        ],
        'voteAverage' => 8.4,
    ], now()->addDays(7));
}

test('episode detail requires authentication', function () {
    $episode = Episode::factory()->create();

    $this->getJson("/api/v1/episodes/{$episode->id}")->assertUnauthorized();
});

test('a missing episode returns 404', function () {
    $this->actingAs(User::factory()->create(), 'sanctum');

    $this->getJson('/api/v1/episodes/999999')->assertNotFound();
});

test('episode detail matches the contract shape for an aired episode with warm credits cache', function () {
    $this->actingAs(User::factory()->create(), 'sanctum');

    $title = Title::factory()->show()->create(['name' => 'Breaking Bad']);
    $season = Season::factory()->for($title)->create(['season_number' => 1, 'episode_count' => 3]);
    $episodes = Episode::factory()->aired()->for($season)->count(3)->sequence(
        ['episode_number' => 1],
        ['episode_number' => 2],
        ['episode_number' => 3],
    )->create(['title_id' => $title->id, 'season_number' => 1]);

    $middle = $episodes->get(1);
    $play = Play::factory()->for($middle, 'playable')->create(['source' => PlaySource::Manual]);
    warmApiEpisodeCredits($middle);

    $response = $this->getJson("/api/v1/episodes/{$middle->id}");

    $response->assertOk()->assertJson([
        'id' => $middle->id,
        'title' => ['id' => $title->id, 'name' => 'Breaking Bad'],
        'season_number' => 1,
        'episode_number' => 2,
        'vote_average' => 8.4,
        'credits_pending' => false,
        'previous_episode_id' => $episodes->first()->id,
        'next_episode_id' => $episodes->last()->id,
        'season_stats' => ['total' => 3, 'watched' => 1],
        'cast' => [
            ['name' => 'Bryan Cranston', 'character' => 'Walter White'],
        ],
        'crew' => [
            ['name' => 'Vince Gilligan', 'job' => 'Director'],
        ],
        'plays' => [
            ['id' => $play->id, 'source' => 'manual'],
        ],
    ]);
    Http::assertNothingSent();
});

test('an aired episode with no cached credits returns pending immediately and dispatches a fetch job', function () {
    Queue::fake();
    $this->actingAs(User::factory()->create(), 'sanctum');

    $title = Title::factory()->show()->create();
    $season = Season::factory()->for($title)->create(['season_number' => 1]);
    $episode = Episode::factory()->aired()->for($season)->create(['title_id' => $title->id, 'season_number' => 1]);

    $response = $this->getJson("/api/v1/episodes/{$episode->id}");

    $response->assertOk()->assertJson([
        'credits_pending' => true,
        'vote_average' => null,
        'cast' => [],
        'crew' => [],
    ]);

    Http::assertNothingSent();
    Queue::assertPushed(ResolveEpisodeCredits::class, fn (ResolveEpisodeCredits $job): bool => $job->episode->is($episode));
});

test('running the dispatched credits job caches credits so a later request returns them inline', function () {
    $this->actingAs(User::factory()->create(), 'sanctum');
    fakeApiEpisodeCredits();

    $title = Title::factory()->show()->create();
    $season = Season::factory()->for($title)->create(['season_number' => 1]);
    $episode = Episode::factory()->aired()->for($season)->create(['title_id' => $title->id, 'season_number' => 1]);

    $this->getJson("/api/v1/episodes/{$episode->id}")->assertJson(['credits_pending' => true]);

    (new ResolveEpisodeCredits($episode))->handle(app(FetchEpisodeCredits::class));

    $response = $this->getJson("/api/v1/episodes/{$episode->id}");

    $response->assertOk()->assertJson([
        'credits_pending' => false,
        'vote_average' => 8.4,
        'cast' => [
            ['name' => 'Bryan Cranston', 'character' => 'Walter White'],
        ],
    ]);
});

test('an unaired episode has no credits or vote average, skips the tmdb lookup, and is not pending', function () {
    $this->actingAs(User::factory()->create(), 'sanctum');

    $title = Title::factory()->show()->create();
    $season = Season::factory()->for($title)->create(['season_number' => 1]);
    $episode = Episode::factory()->unaired()->for($season)->create(['title_id' => $title->id, 'season_number' => 1]);

    $response = $this->getJson("/api/v1/episodes/{$episode->id}");

    $response->assertOk()->assertJson([
        'has_aired' => false,
        'vote_average' => null,
        'cast' => [],
        'crew' => [],
        'credits_pending' => false,
    ]);

    Http::assertNothingSent();
});

test('the first and last episode of a show have null previous/next episode ids', function () {
    $this->actingAs(User::factory()->create(), 'sanctum');

    $title = Title::factory()->show()->create();
    $season = Season::factory()->for($title)->create(['season_number' => 1]);
    $episode = Episode::factory()->unaired()->for($season)->create(['title_id' => $title->id, 'season_number' => 1]);

    $response = $this->getJson("/api/v1/episodes/{$episode->id}");

    $response->assertOk()->assertJson([
        'previous_episode_id' => null,
        'next_episode_id' => null,
    ]);
});
