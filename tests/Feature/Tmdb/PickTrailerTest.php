<?php

use App\Actions\Tmdb\PickTrailer;

test('an official trailer beats a teaser', function () {
    $trailer = app(PickTrailer::class)->handle([
        ['site' => 'YouTube', 'type' => 'Teaser', 'key' => 'teaser-key', 'official' => true, 'iso_639_1' => 'en', 'published_at' => '2020-01-01T00:00:00.000Z'],
        ['site' => 'YouTube', 'type' => 'Trailer', 'key' => 'trailer-key', 'official' => true, 'iso_639_1' => 'en', 'published_at' => '2020-01-02T00:00:00.000Z'],
    ]);

    expect($trailer)->toBe(['site' => 'YouTube', 'key' => 'trailer-key', 'name' => null]);
});

test('an official trailer beats an unofficial one of the same type', function () {
    $trailer = app(PickTrailer::class)->handle([
        ['site' => 'YouTube', 'type' => 'Trailer', 'key' => 'unofficial-key', 'official' => false, 'iso_639_1' => 'en', 'published_at' => '2020-01-05T00:00:00.000Z'],
        ['site' => 'YouTube', 'type' => 'Trailer', 'key' => 'official-key', 'official' => true, 'iso_639_1' => 'en', 'published_at' => '2020-01-01T00:00:00.000Z'],
    ]);

    expect($trailer['key'])->toBe('official-key');
});

test('the preferred language is chosen over english, and english over any other language', function () {
    $trailer = app(PickTrailer::class)->handle([
        ['site' => 'YouTube', 'type' => 'Trailer', 'key' => 'de-key', 'official' => true, 'iso_639_1' => 'de', 'published_at' => '2020-01-01T00:00:00.000Z'],
        ['site' => 'YouTube', 'type' => 'Trailer', 'key' => 'en-key', 'official' => true, 'iso_639_1' => 'en', 'published_at' => '2020-01-01T00:00:00.000Z'],
        ['site' => 'YouTube', 'type' => 'Trailer', 'key' => 'fr-key', 'official' => true, 'iso_639_1' => 'fr', 'published_at' => '2020-01-01T00:00:00.000Z'],
    ], preferredLanguage: 'fr');

    expect($trailer['key'])->toBe('fr-key');

    $trailer = app(PickTrailer::class)->handle([
        ['site' => 'YouTube', 'type' => 'Trailer', 'key' => 'de-key', 'official' => true, 'iso_639_1' => 'de', 'published_at' => '2020-01-01T00:00:00.000Z'],
        ['site' => 'YouTube', 'type' => 'Trailer', 'key' => 'en-key', 'official' => true, 'iso_639_1' => 'en', 'published_at' => '2020-01-01T00:00:00.000Z'],
    ], preferredLanguage: 'fr');

    expect($trailer['key'])->toBe('en-key');
});

test('the newest published trailer wins a tie', function () {
    $trailer = app(PickTrailer::class)->handle([
        ['site' => 'YouTube', 'type' => 'Trailer', 'key' => 'older-key', 'official' => true, 'iso_639_1' => 'en', 'published_at' => '2020-01-01T00:00:00.000Z'],
        ['site' => 'YouTube', 'type' => 'Trailer', 'key' => 'newer-key', 'official' => true, 'iso_639_1' => 'en', 'published_at' => '2021-01-01T00:00:00.000Z'],
    ]);

    expect($trailer['key'])->toBe('newer-key');
});

test('a video on an unsupported site is ignored', function () {
    $trailer = app(PickTrailer::class)->handle([
        ['site' => 'Facebook', 'type' => 'Trailer', 'key' => 'fb-key', 'official' => true, 'iso_639_1' => 'en', 'published_at' => '2020-01-01T00:00:00.000Z'],
    ]);

    expect($trailer)->toBeNull();
});

test('a video type other than trailer or teaser is ignored', function () {
    $trailer = app(PickTrailer::class)->handle([
        ['site' => 'YouTube', 'type' => 'Clip', 'key' => 'clip-key', 'official' => true, 'iso_639_1' => 'en', 'published_at' => '2020-01-01T00:00:00.000Z'],
    ]);

    expect($trailer)->toBeNull();
});

test('no videos returns null', function () {
    expect(app(PickTrailer::class)->handle([]))->toBeNull();
});
