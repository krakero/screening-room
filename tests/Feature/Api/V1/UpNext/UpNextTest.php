<?php

use App\Enums\FollowState;
use App\Models\Episode;
use App\Models\Follow;
use App\Models\Play;
use App\Models\PlexLibraryEpisode;
use App\Models\PlexLibraryItem;
use App\Models\Season;
use App\Models\Title;
use App\Models\User;
use App\Support\DisplayTimezone;
use App\Support\IntegrationSettings;
use Illuminate\Support\Facades\DB;

function upNextUser(): User
{
    return User::factory()->create(['timezone' => 'UTC']);
}

test('up-next requires authentication', function () {
    $this->getJson('/api/v1/up-next')->assertUnauthorized();
});

test('up-next returns the contract shape', function () {
    $user = upNextUser();

    $response = $this->actingAs($user, 'sanctum')->getJson('/api/v1/up-next');

    $response->assertOk()->assertJsonStructure([
        'greeting',
        'continue_watching',
        'airing_this_week',
        'recently_added',
    ]);
    expect($response->json())->not->toHaveKey('abandoned');
});

test('up-next continue watching shows the next unwatched episode for watched shows', function () {
    $user = upNextUser();

    $show = Title::factory()->show()->create();
    $season = Season::factory()->for($show)->create(['season_number' => 1]);
    $watchedEpisode = Episode::factory()->for($season)->aired()->create(['season_number' => 1, 'episode_number' => 1]);
    $nextEpisode = Episode::factory()->for($season)->aired()->create(['season_number' => 1, 'episode_number' => 2]);
    // Logging a play on a show's episode auto-creates/updates its Follow to Watching (PlayObserver).
    Play::factory()->for($watchedEpisode, 'playable')->create(['watched_at' => now()]);

    $response = $this->actingAs($user, 'sanctum')->getJson('/api/v1/up-next');

    $response->assertOk();
    expect($response->json('continue_watching'))->toHaveCount(1);
    expect($response->json('continue_watching.0.title.id'))->toBe($show->id);
    expect($response->json('continue_watching.0.next_episode.id'))->toBe($nextEpisode->id);
    expect($response->json('continue_watching.0.next_episode.code'))->toBe('S01E02');
});

test('up-next airing this week groups episodes by local day and hides paused/abandoned shows', function () {
    $user = upNextUser();
    $today = DisplayTimezone::today();

    $watchedShow = Title::factory()->show()->create(['name' => 'Watching Show']);
    $watchingSeason = Season::factory()->for($watchedShow)->create(['season_number' => 1]);
    $airingEpisode = Episode::factory()->for($watchingSeason)->create([
        'season_number' => 1,
        'episode_number' => 1,
        'air_date' => $today->clone()->addDays(2),
    ]);
    Follow::factory()->for($watchedShow)->create(['state' => FollowState::Watching]);

    $pausedShow = Title::factory()->show()->create(['name' => 'Paused Show']);
    $pausedSeason = Season::factory()->for($pausedShow)->create(['season_number' => 1]);
    Episode::factory()->for($pausedSeason)->create([
        'season_number' => 1,
        'episode_number' => 1,
        'air_date' => $today->clone()->addDays(3),
    ]);
    Follow::factory()->for($pausedShow)->paused()->create();

    $abandonedShow = Title::factory()->show()->create(['name' => 'Abandoned Show']);
    $abandonedSeason = Season::factory()->for($abandonedShow)->create(['season_number' => 1]);
    Episode::factory()->for($abandonedSeason)->create([
        'season_number' => 1,
        'episode_number' => 1,
        'air_date' => $today->clone()->addDays(3),
    ]);
    Follow::factory()->for($abandonedShow)->abandoned()->create();

    $response = $this->actingAs($user, 'sanctum')->getJson('/api/v1/up-next');

    $response->assertOk();

    $dayKey = $airingEpisode->air_date->toDateString();
    $airingThisWeek = $response->json('airing_this_week');

    expect($airingThisWeek)->toHaveKey($dayKey);
    expect($airingThisWeek[$dayKey])->toHaveCount(1);
    expect($airingThisWeek[$dayKey][0]['id'])->toBe($airingEpisode->id);
    expect($airingThisWeek[$dayKey][0]['can_mark_watched'])->toBeFalse();

    $allEpisodeIds = collect($airingThisWeek)->flatten(1)->pluck('id');
    expect($allEpisodeIds)->toHaveCount(1);
});

test('up-next recently added lists only Plex-added titles within 14 days', function () {
    $user = upNextUser();
    app(IntegrationSettings::class)->setMany(['plex.url' => 'http://plex.local', 'plex.token' => 'tok']);

    $recent = Title::factory()->movie()->create(['name' => 'Recent Movie', 'tmdb_id' => 11111]);
    PlexLibraryItem::factory()->create(['type' => 'movie', 'tmdb_id' => 11111, 'added_at' => now()->subDays(5)]);

    $old = Title::factory()->movie()->create(['name' => 'Old Movie', 'tmdb_id' => 22222]);
    PlexLibraryItem::factory()->create(['type' => 'movie', 'tmdb_id' => 22222, 'added_at' => now()->subDays(20)]);

    $unlinked = Title::factory()->movie()->create(['name' => 'Not On Plex', 'tmdb_id' => 33333]);

    $response = $this->actingAs($user, 'sanctum')->getJson('/api/v1/up-next');

    $response->assertOk();
    $ids = collect($response->json('recently_added'))->pluck('id');

    expect($ids)->toContain($recent->id);
    expect($ids)->not->toContain($old->id);
    expect($ids)->not->toContain($unlinked->id);
});

test('up-next recently added includes plex_available, added_at, episode fields and label', function () {
    $user = upNextUser();
    app(IntegrationSettings::class)->setMany(['plex.url' => 'http://plex.local', 'plex.token' => 'tok']);

    $show = Title::factory()->show()->create(['tmdb_id' => 44444]);
    $item = PlexLibraryItem::factory()->show()->create(['tmdb_id' => 44444, 'added_at' => now()->subDays(2)]);
    PlexLibraryEpisode::factory()->create([
        'show_rating_key' => $item->plex_rating_key,
        'machine_identifier' => $item->machine_identifier,
        'season_number' => 3,
        'episode_number' => 7,
        'added_at' => now()->subDays(2),
    ]);

    $response = $this->actingAs($user, 'sanctum')->getJson('/api/v1/up-next');

    $response->assertOk();
    $entry = collect($response->json('recently_added'))->firstWhere('id', $show->id);

    expect($entry)->not->toBeNull();
    expect($entry['plex_available'])->toBeTrue();
    expect($entry['episode_count'])->toBe(1);
    expect($entry['season_number'])->toBe(3);
    expect($entry['episode_number'])->toBe(7);
    expect($entry['label'])->toBe('S3 E7');
    expect($entry['added_at'])->toBeString();
});

test('up-next recently added is empty when Plex is not configured', function () {
    $user = upNextUser();

    Title::factory()->movie()->create(['tmdb_id' => 55551]);
    PlexLibraryItem::factory()->create(['type' => 'movie', 'tmdb_id' => 55551, 'added_at' => now()->subDays(1)]);

    $response = $this->actingAs($user, 'sanctum')->getJson('/api/v1/up-next');

    $response->assertOk();
    expect($response->json('recently_added'))->toBeEmpty();
});

test('up-next runs a bounded number of queries', function () {
    $user = upNextUser();

    foreach (range(1, 3) as $i) {
        $show = Title::factory()->show()->create();
        $season = Season::factory()->for($show)->create(['season_number' => 1]);
        Episode::factory()->for($season)->create([
            'season_number' => 1,
            'episode_number' => 1,
            'air_date' => DisplayTimezone::today()->clone()->addDay(),
        ]);
        Follow::factory()->for($show)->create(['state' => FollowState::Watching]);
    }

    DB::enableQueryLog();

    $response = $this->actingAs($user, 'sanctum')->getJson('/api/v1/up-next');

    $queryCount = count(DB::getQueryLog());
    DB::disableQueryLog();

    $response->assertOk();
    expect($queryCount)->toBeLessThan(25);
});
