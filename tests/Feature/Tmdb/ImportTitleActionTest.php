<?php

use App\Actions\Tmdb\ImportMovie;
use App\Actions\Tmdb\ImportShow;
use App\Actions\Tmdb\ImportTitle;
use App\Enums\TitleType;
use App\Models\Title;

test('it delegates movies to import movie', function () {
    $title = Title::factory()->make(['id' => 1]);

    $importMovie = Mockery::mock(ImportMovie::class);
    $importMovie->shouldReceive('handle')->once()->with(603)->andReturn($title);
    $importShow = Mockery::mock(ImportShow::class);
    $importShow->shouldNotReceive('handle');

    $result = (new ImportTitle($importMovie, $importShow))->handle(TitleType::Movie, 603);

    expect($result)->toBe($title);
});

test('it delegates shows to import show', function () {
    $title = Title::factory()->make(['id' => 2]);

    $importMovie = Mockery::mock(ImportMovie::class);
    $importMovie->shouldNotReceive('handle');
    $importShow = Mockery::mock(ImportShow::class);
    $importShow->shouldReceive('handle')->once()->with(1396)->andReturn($title);

    $result = (new ImportTitle($importMovie, $importShow))->handle(TitleType::Show, 1396);

    expect($result)->toBe($title);
});
