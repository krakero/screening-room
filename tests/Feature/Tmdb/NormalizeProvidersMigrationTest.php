<?php

use App\Models\Title;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Runs the conversion migration's down() to get the old table shapes back, seeds old-shape rows,
 * then runs up() and asserts on the normalized tables.
 */
function conversionMigration(): object
{
    return require database_path('migrations/2026_09_29_150000_normalize_watch_providers_and_networks.php');
}

function oldProviderRow(int $titleId, int $providerId, string $name, array $overrides = []): array
{
    return array_merge([
        'title_id' => $titleId,
        'provider_id' => $providerId,
        'provider_name' => $name,
        'logo_path' => "/{$providerId}.jpg",
        'type' => 'flatrate',
        'region' => 'US',
        'display_priority' => 1,
        'created_at' => '2026-01-01 00:00:00',
        'updated_at' => '2026-01-01 00:00:00',
    ], $overrides);
}

beforeEach(function () {
    conversionMigration()->down();
});

// The schema changes implicitly commit on MySQL, so RefreshDatabase's rollback can't clean up after us.
afterEach(function () {
    if (Schema::hasTable('title_watch_providers')) {
        conversionMigration()->up();
    }

    DB::table('titles')->delete();
    DB::table('watch_providers')->delete();
    DB::table('networks')->delete();
});

test('providers are deduplicated into watch_providers using the most recent row and channel variants are dropped', function () {
    [$a, $b] = Title::factory()->count(2)->create();

    DB::table('title_watch_providers')->insert([
        oldProviderRow($a->id, 8, 'Netflix Old', ['display_priority' => 5, 'updated_at' => '2026-01-01 00:00:00']),
        oldProviderRow($b->id, 8, 'Netflix', ['logo_path' => '/new.jpg', 'display_priority' => 0, 'updated_at' => '2026-03-01 00:00:00']),
        oldProviderRow($a->id, 8, 'Netflix Old', ['type' => 'free', 'display_priority' => 5, 'updated_at' => '2026-01-01 00:00:00']),
        oldProviderRow($a->id, 119, 'HBO Max Amazon Channel'),
        oldProviderRow($b->id, 120, 'Starz Apple TV Channel'),
        oldProviderRow($b->id, 73, 'Tubi', ['type' => 'ads', 'region' => 'GB']),
    ]);

    conversionMigration()->up();

    expect(Schema::hasTable('title_watch_providers'))->toBeFalse()
        ->and(DB::table('watch_providers')->orderBy('tmdb_id')->get(['tmdb_id', 'name', 'logo_path', 'display_priority'])->map(fn ($p): array => (array) $p)->all())->toBe([
            ['tmdb_id' => 8, 'name' => 'Netflix', 'logo_path' => '/new.jpg', 'display_priority' => 0],
            ['tmdb_id' => 73, 'name' => 'Tubi', 'logo_path' => '/73.jpg', 'display_priority' => 1],
        ]);

    $pivots = DB::table('title_watch_provider')
        ->join('watch_providers', 'watch_providers.id', '=', 'title_watch_provider.watch_provider_id')
        ->orderBy('title_id')->orderBy('tmdb_id')->orderBy('type')
        ->get(['title_id', 'tmdb_id', 'type', 'region'])
        ->map(fn ($row): string => "{$row->title_id}:{$row->tmdb_id}:{$row->type}:{$row->region}")
        ->all();

    expect($pivots)->toBe([
        "{$a->id}:8:flatrate:US",
        "{$a->id}:8:free:US",
        "{$b->id}:8:flatrate:US",
        "{$b->id}:73:ads:GB",
    ]);
});

test('the networks JSON becomes network rows and ordered pivot rows and the column is dropped', function () {
    $a = Title::factory()->create();
    $b = Title::factory()->create();
    $none = Title::factory()->create();

    DB::table('titles')->where('id', $a->id)->update(['networks' => json_encode([
        ['id' => 174, 'name' => 'AMC', 'logo_path' => '/amc.png'],
        ['id' => 19, 'name' => 'FOX', 'logo_path' => null],
    ])]);
    DB::table('titles')->where('id', $b->id)->update(['networks' => json_encode([
        ['id' => 19, 'name' => 'FOX', 'logo_path' => null],
        ['id' => 174, 'name' => 'AMC', 'logo_path' => '/amc.png'],
    ])]);
    DB::table('titles')->where('id', $none->id)->update(['networks' => json_encode([])]);

    conversionMigration()->up();

    $rows = fn (int $titleId): array => DB::table('network_title')
        ->join('networks', 'networks.id', '=', 'network_title.network_id')
        ->where('title_id', $titleId)->orderBy('position')
        ->get(['tmdb_id', 'name', 'position'])->map(fn ($row): array => (array) $row)->all();

    expect(Schema::hasColumn('titles', 'networks'))->toBeFalse()
        ->and(DB::table('networks')->count())->toBe(2)
        ->and($rows($a->id))->toBe([['tmdb_id' => 174, 'name' => 'AMC', 'position' => 0], ['tmdb_id' => 19, 'name' => 'FOX', 'position' => 1]])
        ->and($rows($b->id))->toBe([['tmdb_id' => 19, 'name' => 'FOX', 'position' => 0], ['tmdb_id' => 174, 'name' => 'AMC', 'position' => 1]])
        ->and($rows($none->id))->toBe([]);
});

test('down restores the old shapes with the data copied back', function () {
    $title = Title::factory()->create();

    DB::table('title_watch_providers')->insert(oldProviderRow($title->id, 8, 'Netflix'));
    DB::table('titles')->where('id', $title->id)->update(['networks' => json_encode([
        ['id' => 174, 'name' => 'AMC', 'logo_path' => '/amc.png'],
        ['id' => 19, 'name' => 'FOX', 'logo_path' => null],
    ])]);

    conversionMigration()->up();
    conversionMigration()->down();

    expect(Schema::hasTable('title_watch_provider'))->toBeFalse()
        ->and(DB::table('title_watch_providers')->first(['title_id', 'provider_id', 'provider_name', 'type', 'region']))->toEqual((object) [
            'title_id' => $title->id, 'provider_id' => 8, 'provider_name' => 'Netflix', 'type' => 'flatrate', 'region' => 'US',
        ])
        ->and(json_decode(DB::table('titles')->where('id', $title->id)->value('networks'), true))->toBe([
            ['id' => 174, 'name' => 'AMC', 'logo_path' => '/amc.png'],
            ['id' => 19, 'name' => 'FOX', 'logo_path' => null],
        ]);

    conversionMigration()->up();
});
