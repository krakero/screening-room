<?php

use App\Enums\CollectionFormat;
use App\Models\CollectionItem;
use App\Models\PlexItem;
use App\Models\Season;
use App\Models\Title;
use App\Models\User;
use App\Services\Collection\CollectionStats;

test('returns empty stats when collection is disabled', function () {
    $user = User::factory()->create(['collection_enabled' => false]);
    $this->actingAs($user);

    CollectionItem::factory()->create();

    $stats = app(CollectionStats::class)->summary();

    expect($stats['copies'])->toBe(0)
        ->and($stats['titles'])->toBe(0)
        ->and($stats['movies'])->toBe(0)
        ->and($stats['shows'])->toBe(0);
});

test('returns empty stats when no items exist', function () {
    $user = User::factory()->create(['collection_enabled' => true]);
    $this->actingAs($user);

    $stats = app(CollectionStats::class)->summary();

    expect($stats['copies'])->toBe(0)
        ->and($stats['titles'])->toBe(0);
});

test('counts total copies correctly', function () {
    $user = User::factory()->create(['collection_enabled' => true]);
    $this->actingAs($user);

    $title = Title::factory()->movie()->create();
    CollectionItem::factory()->count(3)->for($title)->create();

    $stats = app(CollectionStats::class)->summary();

    expect($stats['copies'])->toBe(3);
});

test('counts distinct titles correctly', function () {
    $user = User::factory()->create(['collection_enabled' => true]);
    $this->actingAs($user);

    $title1 = Title::factory()->movie()->create();
    $title2 = Title::factory()->movie()->create();

    CollectionItem::factory()->count(2)->for($title1)->create();
    CollectionItem::factory()->for($title2)->create();

    $stats = app(CollectionStats::class)->summary();

    expect($stats['titles'])->toBe(2)
        ->and($stats['copies'])->toBe(3);
});

test('counts movies and shows separately', function () {
    $user = User::factory()->create(['collection_enabled' => true]);
    $this->actingAs($user);

    $movie1 = Title::factory()->movie()->create();
    $movie2 = Title::factory()->movie()->create();
    $show = Title::factory()->show()->create();

    CollectionItem::factory()->for($movie1)->create();
    CollectionItem::factory()->for($movie2)->create();
    CollectionItem::factory()->for($show)->create();

    $stats = app(CollectionStats::class)->summary();

    expect($stats['movies'])->toBe(2)
        ->and($stats['shows'])->toBe(1)
        ->and($stats['titles'])->toBe(3);
});

test('breaks down copies by format', function () {
    $user = User::factory()->create(['collection_enabled' => true]);
    $this->actingAs($user);

    $title = Title::factory()->movie()->create();

    CollectionItem::factory()->for($title)->create(['format' => CollectionFormat::Uhd4k]);
    CollectionItem::factory()->for($title)->create(['format' => CollectionFormat::BluRay]);
    CollectionItem::factory()->for($title)->create(['format' => CollectionFormat::BluRay]);
    CollectionItem::factory()->for($title)->create(['format' => CollectionFormat::Digital]);

    $stats = app(CollectionStats::class)->summary();

    expect($stats['by_format'])->toBe([
        'uhd_4k' => 1,
        'bluray' => 2,
        'digital' => 1,
    ]);
});

test('calculates total spending by currency', function () {
    $user = User::factory()->create(['collection_enabled' => true]);
    $this->actingAs($user);

    $title = Title::factory()->movie()->create();

    CollectionItem::factory()->for($title)->create(['price' => 19.99, 'currency' => 'USD']);
    CollectionItem::factory()->for($title)->create(['price' => 29.99, 'currency' => 'USD']);
    CollectionItem::factory()->for($title)->create(['price' => 15.50, 'currency' => 'EUR']);

    $stats = app(CollectionStats::class)->summary();

    expect($stats['total_spent'])->toBe([
        'USD' => 49.98,
        'EUR' => 15.50,
    ]);
});

test('ignores items without price or currency in spending calculation', function () {
    $user = User::factory()->create(['collection_enabled' => true]);
    $this->actingAs($user);

    $title = Title::factory()->movie()->create();

    CollectionItem::factory()->for($title)->create(['price' => 19.99, 'currency' => 'USD']);
    CollectionItem::factory()->for($title)->create(['price' => null, 'currency' => 'USD']);
    CollectionItem::factory()->for($title)->create(['price' => 15.50, 'currency' => null]);

    $stats = app(CollectionStats::class)->summary();

    expect($stats['total_spent'])->toBe([
        'USD' => 19.99,
    ]);
});

test('counts loaned out items', function () {
    $user = User::factory()->create(['collection_enabled' => true]);
    $this->actingAs($user);

    $title = Title::factory()->movie()->create();

    CollectionItem::factory()->for($title)->loaned()->count(2)->create();
    CollectionItem::factory()->for($title)->create();

    $stats = app(CollectionStats::class)->summary();

    expect($stats['loaned_out'])->toBe(2);
});

test('counts titles in Plex without a collection copy', function () {
    $user = User::factory()->create(['collection_enabled' => true]);
    $this->actingAs($user);

    $titleWithCopy = Title::factory()->movie()->create();
    $titleInPlexOnly = Title::factory()->movie()->create();
    $titleNotInPlex = Title::factory()->movie()->create();

    CollectionItem::factory()->for($titleWithCopy)->create();
    PlexItem::factory()->for($titleWithCopy, 'plexable')->create(['rating_key' => '123']);
    PlexItem::factory()->for($titleInPlexOnly, 'plexable')->create(['rating_key' => '456']);

    $stats = app(CollectionStats::class)->summary();

    expect($stats['in_plex_only'])->toBe(1);
});

test('counts items added this year', function () {
    $user = User::factory()->create(['collection_enabled' => true]);
    $this->actingAs($user);

    $title = Title::factory()->movie()->create();
    $currentYear = now()->year;

    CollectionItem::factory()->for($title)->create(['acquired_at' => "{$currentYear}-06-15"]);
    CollectionItem::factory()->for($title)->create(['acquired_at' => "{$currentYear}-12-31"]);
    CollectionItem::factory()->for($title)->create(['acquired_at' => ($currentYear - 1).'-12-31']);

    $stats = app(CollectionStats::class)->summary();

    expect($stats['added_this_year'])->toBe(2);
});

test('year filter scopes all stats except in_plex_only and added_this_year', function () {
    $user = User::factory()->create(['collection_enabled' => true]);
    $this->actingAs($user);

    $title = Title::factory()->movie()->create();

    CollectionItem::factory()->for($title)->create([
        'acquired_at' => '2024-06-15',
        'format' => CollectionFormat::Uhd4k,
        'price' => 29.99,
        'currency' => 'USD',
    ]);

    CollectionItem::factory()->for($title)->create([
        'acquired_at' => '2025-06-15',
        'format' => CollectionFormat::BluRay,
        'price' => 19.99,
        'currency' => 'USD',
    ]);

    $stats2024 = app(CollectionStats::class)->summary(2024);

    expect($stats2024['copies'])->toBe(1)
        ->and($stats2024['titles'])->toBe(1)
        ->and($stats2024['movies'])->toBe(1)
        ->and($stats2024['by_format'])->toBe(['uhd_4k' => 1])
        ->and($stats2024['total_spent'])->toBe(['USD' => 29.99]);
});

test('year filter respects user timezone for acquired_at', function () {
    $user = User::factory()->create([
        'collection_enabled' => true,
        'timezone' => 'America/New_York',
    ]);
    $this->actingAs($user);

    $title = Title::factory()->movie()->create();

    // 2026-01-01 02:00 UTC is still 2025-12-31 21:00 in America/New_York
    CollectionItem::factory()->for($title)->create(['acquired_at' => '2026-01-01 02:00:00']);

    $stats2025 = app(CollectionStats::class)->summary(2025);
    $stats2026 = app(CollectionStats::class)->summary(2026);

    expect($stats2025['copies'])->toBe(1)
        ->and($stats2026['copies'])->toBe(0);
});

test('counts season-specific collection items towards show totals', function () {
    $user = User::factory()->create(['collection_enabled' => true]);
    $this->actingAs($user);

    $show = Title::factory()->show()->create();
    $season = Season::factory()->for($show)->create(['season_number' => 1]);

    CollectionItem::factory()->forSeason($season)->create();

    $stats = app(CollectionStats::class)->summary();

    expect($stats['shows'])->toBe(1)
        ->and($stats['titles'])->toBe(1)
        ->and($stats['copies'])->toBe(1);
});
