<?php

use App\Enums\CollectionFormat;
use App\Models\CollectionItem;
use App\Models\PlexItem;
use App\Models\PlexLibraryEpisode;
use App\Models\Season;
use App\Models\Title;
use App\Models\User;
use App\Services\Collection\Ownership;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

test('forTitle returns ownership summary with copies', function () {
    $user = User::factory()->create(['collection_enabled' => true]);
    $this->actingAs($user);

    $title = Title::factory()->create();
    $item1 = CollectionItem::factory()->create(['title_id' => $title->id, 'format' => CollectionFormat::BluRay]);
    $item2 = CollectionItem::factory()->create(['title_id' => $title->id, 'format' => CollectionFormat::Digital]);

    $ownership = new Ownership;
    $summary = $ownership->forTitle($title);

    expect($summary->isOwned())->toBeTrue()
        ->and($summary->copies)->toHaveCount(2)
        ->and($summary->inPlex)->toBeFalse()
        ->and($summary->formats())->toContain(CollectionFormat::BluRay, CollectionFormat::Digital)
        ->and($summary->label())->toContain('Blu-ray', 'Digital');
});

test('forTitle returns ownership summary with plex item', function () {
    $user = User::factory()->create(['collection_enabled' => true]);
    $this->actingAs($user);

    $title = Title::factory()->create();
    PlexItem::factory()->for($title, 'plexable')->create();
    $title->load('plexItem');

    $ownership = new Ownership;
    $summary = $ownership->forTitle($title);

    expect($summary->isOwned())->toBeTrue()
        ->and($summary->copies)->toBeEmpty()
        ->and($summary->inPlex)->toBeTrue()
        ->and($summary->label())->toBe('Plex');
});

test('forSeason returns ownership summary with copies', function () {
    $user = User::factory()->create(['collection_enabled' => true]);
    $this->actingAs($user);

    $title = Title::factory()->create();
    $season = Season::factory()->create(['title_id' => $title->id, 'season_number' => 1]);
    CollectionItem::factory()->forSeason($season)->create(['format' => CollectionFormat::Dvd]);

    $ownership = new Ownership;
    $summary = $ownership->forSeason($season);

    expect($summary->isOwned())->toBeTrue()
        ->and($summary->copies)->toHaveCount(1)
        ->and($summary->inPlex)->toBeFalse();
});

test('forSeason detects plex availability', function () {
    $user = User::factory()->create(['collection_enabled' => true]);
    $this->actingAs($user);

    $title = Title::factory()->create();
    $season = Season::factory()->create(['title_id' => $title->id, 'season_number' => 2]);

    $plexItem = PlexItem::factory()->for($title, 'plexable')->create();
    $title->load('plexItem');

    PlexLibraryEpisode::factory()->create([
        'show_rating_key' => $plexItem->rating_key,
        'season_number' => 2,
        'episode_number' => 1,
    ]);

    $season->load('title.plexItem');
    $ownership = new Ownership;
    $summary = $ownership->forSeason($season);

    expect($summary->isOwned())->toBeTrue()
        ->and($summary->inPlex)->toBeTrue();
});

test('disabled user returns not owned', function () {
    $user = User::factory()->create(['collection_enabled' => false]);
    $this->actingAs($user);

    $title = Title::factory()->create();
    CollectionItem::factory()->create(['title_id' => $title->id]);

    $ownership = new Ownership;
    $summary = $ownership->forTitle($title);

    expect($summary->isOwned())->toBeFalse()
        ->and($summary->copies)->toBeEmpty()
        ->and($summary->inPlex)->toBeFalse();
});

test('forTitles batches and avoids N+1', function () {
    $user = User::factory()->create(['collection_enabled' => true]);
    $this->actingAs($user);

    $titles = Title::factory()->count(3)->create();

    CollectionItem::factory()->create(['title_id' => $titles[0]->id, 'format' => CollectionFormat::BluRay]);
    CollectionItem::factory()->create(['title_id' => $titles[0]->id, 'format' => CollectionFormat::Digital]);
    CollectionItem::factory()->create(['title_id' => $titles[1]->id, 'format' => CollectionFormat::Dvd]);

    PlexItem::factory()->for($titles[2], 'plexable')->create();

    $titles = Title::with('plexItem')->find($titles->pluck('id'));

    DB::enableQueryLog();
    $ownership = new Ownership;
    $summaries = $ownership->forTitles($titles);
    $queryCount = count(DB::getQueryLog());

    expect($queryCount)->toBe(1)
        ->and($summaries)->toHaveCount(3)
        ->and($summaries[$titles[0]->id]->copies)->toHaveCount(2)
        ->and($summaries[$titles[0]->id]->isOwned())->toBeTrue()
        ->and($summaries[$titles[1]->id]->copies)->toHaveCount(1)
        ->and($summaries[$titles[1]->id]->isOwned())->toBeTrue()
        ->and($summaries[$titles[2]->id]->copies)->toBeEmpty()
        ->and($summaries[$titles[2]->id]->inPlex)->toBeTrue()
        ->and($summaries[$titles[2]->id]->isOwned())->toBeTrue();
});

test('isEnabled returns false when no user', function () {
    $ownership = new Ownership;

    expect($ownership->isEnabled())->toBeFalse();
});

test('isEnabled returns true when user has collection enabled', function () {
    $user = User::factory()->create(['collection_enabled' => true]);
    $this->actingAs($user);

    $ownership = new Ownership;

    expect($ownership->isEnabled())->toBeTrue();
});

test('forTitles eager-loads plex items so strict lazy loading does not throw', function () {
    $this->actingAs(User::factory()->create(['collection_enabled' => true]));

    $titles = Title::factory()->count(3)->create();
    PlexItem::factory()->for($titles->first(), 'plexable')->create();

    Model::preventLazyLoading(true);

    try {
        $summaries = app(Ownership::class)->forTitles(Title::query()->whereIn('id', $titles->pluck('id'))->get());
    } finally {
        Model::preventLazyLoading(false);
    }

    expect($summaries)->toHaveCount(3)
        ->and($summaries[$titles->first()->id]->inPlex)->toBeTrue();
});
