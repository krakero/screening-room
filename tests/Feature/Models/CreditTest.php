<?php

use App\Enums\CreditType;
use App\Models\Credit;
use App\Models\Person;
use App\Models\Title;

test('casts type to enum and resolves relations', function () {
    $person = Person::factory()->create();
    $title = Title::factory()->create();
    $credit = Credit::factory()->for($person)->create([
        'creditable_type' => Title::class,
        'creditable_id' => $title->id,
    ]);

    expect($credit->type)->toBe(CreditType::Cast)
        ->and($credit->person->is($person))->toBeTrue()
        ->and($credit->creditable->is($title))->toBeTrue();
});

test('crew state sets crew fields', function () {
    $credit = Credit::factory()->crew()->create();

    expect($credit->type)->toBe(CreditType::Crew)
        ->and($credit->job)->not->toBeNull()
        ->and($credit->character)->toBeNull();
});
