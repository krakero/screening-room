<?php

use App\Models\Rating;
use App\Models\Title;
use Carbon\CarbonImmutable;

test('score is nullable and casts review_spoilers/reviewed_at', function () {
    $rated = Rating::factory()->create(['score' => 8, 'review_spoilers' => true, 'reviewed_at' => '2026-01-02 00:00:00']);
    $unrated = Rating::factory()->create(['score' => null]);

    expect($rated->score)->toBe(8)
        ->and($rated->review_spoilers)->toBeTrue()
        ->and($rated->reviewed_at)->toBeInstanceOf(CarbonImmutable::class)
        ->and($unrated->score)->toBeNull();
});

test('rateable morphs to a title', function () {
    $title = Title::factory()->create();
    $rating = Rating::factory()->for($title, 'rateable')->create();

    expect($rating->rateable)->toBeInstanceOf(Title::class)
        ->and($rating->rateable->is($title))->toBeTrue();
});
