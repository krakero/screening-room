<?php

use App\Enums\FollowState;
use App\Models\Follow;
use App\Models\Title;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;

test('casts attributes correctly', function () {
    $follow = Follow::factory()->create();

    expect($follow->state)->toBeInstanceOf(FollowState::class)
        ->and($follow->state_changed_at)->toBeInstanceOf(CarbonImmutable::class);
});

test('title relation resolves', function () {
    $title = Title::factory()->show()->create();
    $follow = Follow::factory()->for($title)->create();

    expect($follow->title->is($title))->toBeTrue();
});

test('a title has one follow', function () {
    $title = Title::factory()->show()->create();
    $follow = Follow::factory()->for($title)->create();

    expect($title->follow()->first()->is($follow))->toBeTrue();
});

test('title_id is unique per follow', function () {
    $title = Title::factory()->show()->create();
    Follow::factory()->for($title)->create();

    expect(fn () => Follow::factory()->for($title)->create())->toThrow(QueryException::class);
});
