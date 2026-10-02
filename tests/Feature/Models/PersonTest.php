<?php

use App\Models\Credit;
use App\Models\Person;

test('credits relation resolves', function () {
    $person = Person::factory()->create();
    Credit::factory()->for($person)->count(2)->create();

    expect($person->credits)->toHaveCount(2);
});

test('profileUrl builds the tmdb cdn url or null', function () {
    config(['services.tmdb.image_base_url' => 'https://image.tmdb.org/t/p']);

    $person = Person::factory()->create(['profile_path' => '/person.jpg']);
    $withoutProfile = Person::factory()->create(['profile_path' => null]);

    expect($person->profileUrl())->toBe('https://image.tmdb.org/t/p/w185/person.jpg')
        ->and($withoutProfile->profileUrl())->toBeNull();
});
