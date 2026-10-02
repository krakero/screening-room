<?php

use App\Models\MediaList;
use App\Models\MediaListItem;
use App\Models\Title;

test('watchlist creates a singleton watchlist media list', function () {
    $watchlist = MediaList::watchlist();

    expect($watchlist->slug)->toBe('watchlist')
        ->and($watchlist->name)->toBe('Watchlist')
        ->and($watchlist->is_watchlist)->toBeTrue();

    $again = MediaList::watchlist();

    expect($again->id)->toBe($watchlist->id)
        ->and(MediaList::count())->toBe(1);
});

test('items relation is ordered by position', function () {
    $list = MediaList::factory()->create();
    $titles = Title::factory()->count(3)->create();

    MediaListItem::factory()->for($list)->for($titles[0], 'title')->create(['position' => 2]);
    MediaListItem::factory()->for($list)->for($titles[1], 'title')->create(['position' => 0]);
    MediaListItem::factory()->for($list)->for($titles[2], 'title')->create(['position' => 1]);

    expect($list->items->pluck('position')->all())->toBe([0, 1, 2]);
});

test('titles relation resolves through pivot', function () {
    $list = MediaList::factory()->create();
    $title = Title::factory()->create();
    $list->titles()->attach($title, ['position' => 0]);

    expect($list->titles)->toHaveCount(1)
        ->and($list->titles->first()->is($title))->toBeTrue();
});
