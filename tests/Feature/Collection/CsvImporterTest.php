<?php

use App\Enums\CollectionFormat;
use App\Enums\TitleType;
use App\Services\Collection\CsvImporter;

test('parses valid csv with all columns', function () {
    $csv = tmpCsv([
        'title,year,type,season,format,edition,retailer,barcode,acquired_at,price,currency,location,notes',
        'The Matrix,1999,movie,,4K,Collector\'s Edition,Amazon,123456,2024-01-15,29.99,USD,Shelf A,Still sealed',
    ]);

    $importer = new CsvImporter;
    $result = $importer->parse($csv);

    expect($result['errors'])->toBeEmpty();
    expect($result['rows'])->toHaveCount(1);

    $row = $result['rows'][0];
    expect($row['title'])->toBe('The Matrix');
    expect($row['year'])->toBe(1999);
    expect($row['type'])->toBe(TitleType::Movie);
    expect($row['season'])->toBeNull();
    expect($row['format'])->toBe(CollectionFormat::Uhd4k);
    expect($row['edition'])->toBe('Collector\'s Edition');
    expect($row['retailer'])->toBe('Amazon');
    expect($row['barcode'])->toBe('123456');
    expect($row['acquired_at'])->toBe('2024-01-15');
    expect($row['price'])->toBe(29.99);
    expect($row['currency'])->toBe('USD');
    expect($row['location'])->toBe('Shelf A');
    expect($row['notes'])->toBe('Still sealed');
});

test('parses show with season', function () {
    $csv = tmpCsv([
        'title,year,type,season,format',
        'Breaking Bad,2008,show,1,Blu-ray',
    ]);

    $importer = new CsvImporter;
    $result = $importer->parse($csv);

    expect($result['errors'])->toBeEmpty();
    expect($result['rows'])->toHaveCount(1);
    expect($result['rows'][0]['type'])->toBe(TitleType::Show);
    expect($result['rows'][0]['season'])->toBe(1);
});

test('accepts format variations', function () {
    $csv = tmpCsv([
        'title,format',
        'Movie 1,4K',
        'Movie 2,UHD',
        'Movie 3,4K UHD',
        'Movie 4,Blu-ray',
        'Movie 5,BD',
        'Movie 6,Blu Ray',
        'Movie 7,DVD',
        'Movie 8,Digital',
    ]);

    $importer = new CsvImporter;
    $result = $importer->parse($csv);

    expect($result['errors'])->toBeEmpty();
    expect($result['rows'])->toHaveCount(8);
    expect($result['rows'][0]['format'])->toBe(CollectionFormat::Uhd4k);
    expect($result['rows'][1]['format'])->toBe(CollectionFormat::Uhd4k);
    expect($result['rows'][2]['format'])->toBe(CollectionFormat::Uhd4k);
    expect($result['rows'][3]['format'])->toBe(CollectionFormat::BluRay);
    expect($result['rows'][4]['format'])->toBe(CollectionFormat::BluRay);
    expect($result['rows'][5]['format'])->toBe(CollectionFormat::BluRay);
    expect($result['rows'][6]['format'])->toBe(CollectionFormat::Dvd);
    expect($result['rows'][7]['format'])->toBe(CollectionFormat::Digital);
});

test('defaults empty type to movie', function () {
    $csv = tmpCsv([
        'title,format,type',
        'Some Movie,DVD,',
    ]);

    $importer = new CsvImporter;
    $result = $importer->parse($csv);

    expect($result['errors'])->toBeEmpty();
    expect($result['rows'][0]['type'])->toBe(TitleType::Movie);
});

test('accepts type variations', function () {
    $csv = tmpCsv([
        'title,format,type',
        'Movie,DVD,movie',
        'Show 1,DVD,show',
        'Show 2,DVD,tv',
        'Show 3,DVD,series',
    ]);

    $importer = new CsvImporter;
    $result = $importer->parse($csv);

    expect($result['errors'])->toBeEmpty();
    expect($result['rows'][0]['type'])->toBe(TitleType::Movie);
    expect($result['rows'][1]['type'])->toBe(TitleType::Show);
    expect($result['rows'][2]['type'])->toBe(TitleType::Show);
    expect($result['rows'][3]['type'])->toBe(TitleType::Show);
});

test('reports error for missing title', function () {
    $csv = tmpCsv([
        'title,format',
        ',DVD',
    ]);

    $importer = new CsvImporter;
    $result = $importer->parse($csv);

    expect($result['rows'])->toBeEmpty();
    expect($result['errors'])->toHaveCount(1);
    expect($result['errors'][0]['message'])->toBe('Title is required');
});

test('reports error for missing format', function () {
    $csv = tmpCsv([
        'title,format',
        'Movie,',
    ]);

    $importer = new CsvImporter;
    $result = $importer->parse($csv);

    expect($result['rows'])->toBeEmpty();
    expect($result['errors'])->toHaveCount(1);
    expect($result['errors'][0]['message'])->toBe('Format is required');
});

test('reports error for invalid format', function () {
    $csv = tmpCsv([
        'title,format',
        'Movie,VHS',
    ]);

    $importer = new CsvImporter;
    $result = $importer->parse($csv);

    expect($result['rows'])->toBeEmpty();
    expect($result['errors'])->toHaveCount(1);
    expect($result['errors'][0]['message'])->toContain('Invalid format');
});

test('reports error for invalid type', function () {
    $csv = tmpCsv([
        'title,format,type',
        'Something,DVD,game',
    ]);

    $importer = new CsvImporter;
    $result = $importer->parse($csv);

    expect($result['rows'])->toBeEmpty();
    expect($result['errors'])->toHaveCount(1);
    expect($result['errors'][0]['message'])->toContain('Invalid type');
});

test('reports error for invalid year', function () {
    $csv = tmpCsv([
        'title,format,year',
        'Movie,DVD,abc',
    ]);

    $importer = new CsvImporter;
    $result = $importer->parse($csv);

    expect($result['rows'])->toBeEmpty();
    expect($result['errors'])->toHaveCount(1);
    expect($result['errors'][0]['message'])->toContain('Invalid year');
});

test('skips empty rows', function () {
    $csv = tmpCsv([
        'title,format',
        '',
        'Movie,DVD',
        '',
    ]);

    $importer = new CsvImporter;
    $result = $importer->parse($csv);

    expect($result['errors'])->toBeEmpty();
    expect($result['rows'])->toHaveCount(1);
});

test('handles multiple rows with some errors', function () {
    $csv = tmpCsv([
        'title,format',
        'Valid Movie,DVD',
        ',DVD',
        'Another Valid,Blu-ray',
        'Missing Format,',
    ]);

    $importer = new CsvImporter;
    $result = $importer->parse($csv);

    expect($result['rows'])->toHaveCount(2);
    expect($result['errors'])->toHaveCount(2);
});
