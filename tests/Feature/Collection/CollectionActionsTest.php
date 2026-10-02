<?php

use App\Actions\Collection\AddCollectionItem;
use App\Actions\Collection\RemoveCollectionItem;
use App\Actions\Collection\UpdateCollectionItem;
use App\Enums\CollectionFormat;
use App\Http\Requests\Collection\CollectionItemRequest;
use App\Models\CollectionItem;
use App\Models\Title;
use Illuminate\Support\Facades\Validator;

test('AddCollectionItem creates a collection item', function () {
    $title = Title::factory()->create();

    $action = new AddCollectionItem;
    $item = $action->handle($title, [
        'format' => CollectionFormat::BluRay,
        'edition' => 'Collector\'s Edition',
        'acquired_at' => '2024-01-15',
    ]);

    expect($item)->toBeInstanceOf(CollectionItem::class)
        ->and($item->title_id)->toBe($title->id)
        ->and($item->format)->toBe(CollectionFormat::BluRay)
        ->and($item->edition)->toBe('Collector\'s Edition');
});

test('UpdateCollectionItem updates a collection item', function () {
    $item = CollectionItem::factory()->create(['format' => CollectionFormat::Dvd]);

    $action = new UpdateCollectionItem;
    $updated = $action->handle($item, [
        'format' => CollectionFormat::BluRay,
        'loaned_to' => 'John Doe',
        'loaned_at' => '2024-02-01',
    ]);

    expect($updated->format)->toBe(CollectionFormat::BluRay)
        ->and($updated->loaned_to)->toBe('John Doe')
        ->and($updated->loaned_at->format('Y-m-d'))->toBe('2024-02-01');
});

test('RemoveCollectionItem deletes a collection item', function () {
    $item = CollectionItem::factory()->create();
    $itemId = $item->id;

    $action = new RemoveCollectionItem;
    $action->handle($item);

    expect(CollectionItem::find($itemId))->toBeNull();
});

test('validation rules require format', function () {
    $validator = Validator::make([], CollectionItemRequest::rules());

    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->has('format'))->toBeTrue();
});

test('validation rules accept valid format', function () {
    $validator = Validator::make([
        'format' => 'bluray',
    ], CollectionItemRequest::rules());

    expect($validator->passes())->toBeTrue();
});

test('validation rules reject invalid format', function () {
    $validator = Validator::make([
        'format' => 'invalid',
    ], CollectionItemRequest::rules());

    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->has('format'))->toBeTrue();
});

test('validation rules require numeric price', function () {
    $validator = Validator::make([
        'format' => 'bluray',
        'price' => 'not a number',
    ], CollectionItemRequest::rules());

    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->has('price'))->toBeTrue();
});

test('validation rules reject negative price', function () {
    $validator = Validator::make([
        'format' => 'bluray',
        'price' => -10,
    ], CollectionItemRequest::rules());

    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->has('price'))->toBeTrue();
});

test('validation rules accept valid dates', function () {
    $validator = Validator::make([
        'format' => 'bluray',
        'acquired_at' => '2024-01-15',
        'loaned_at' => '2024-02-01',
    ], CollectionItemRequest::rules());

    expect($validator->passes())->toBeTrue();
});

test('validation rules enforce barcode max length', function () {
    $validator = Validator::make([
        'format' => 'bluray',
        'barcode' => str_repeat('1', 33),
    ], CollectionItemRequest::rules());

    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->has('barcode'))->toBeTrue();
});
