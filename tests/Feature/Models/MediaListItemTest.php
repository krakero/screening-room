<?php

use App\Models\MediaList;
use App\Models\MediaListItem;
use App\Models\Title;

test('mediaList and title relations resolve', function () {
    $list = MediaList::factory()->create();
    $title = Title::factory()->create();
    $item = MediaListItem::factory()->for($list)->for($title)->create();

    expect($item->mediaList->is($list))->toBeTrue()
        ->and($item->title->is($title))->toBeTrue();
});
