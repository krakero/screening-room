<?php

use App\Enums\BackupType;
use App\Jobs\WarmStatsCache;
use App\Models\IntegrationSetting;
use App\Models\Title;
use App\Models\User;
use App\Services\Backup\BackupException;
use App\Services\Backup\BackupManager;
use App\Services\Backup\BackupRestorer;
use App\Services\Stats\StatsCacheVersion;
use App\Services\Watch\WatchCacheVersion;
use App\Support\IntegrationSettings;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

const RESTORER_PASSWORD = 'correct-horse-battery';

beforeEach(function () {
    Queue::fake();

    $this->backupDirectory = sys_get_temp_dir().'/ps-restorer-'.Str::random(12);
    File::makeDirectory($this->backupDirectory, 0775, true);

    $this->settings = app(IntegrationSettings::class);
    $this->settings->set('backup.path', $this->backupDirectory);
    $this->settings->set('backup.password', RESTORER_PASSWORD);
});

afterEach(function () {
    File::deleteDirectory($this->backupDirectory);
});

/**
 * Build an encrypted backup zip per the schema-1 format, without going through BackupManager.
 *
 * @param  array<string, list<array<string, mixed>>>  $tables  Table => rows.
 * @param  array<string, mixed>  $config
 * @param  array<string, mixed>  $manifest  Overrides merged over the generated manifest.
 */
function restorerFixtureZip(string $directory, array $tables = [], array $config = [], array $manifest = [], string $password = RESTORER_PASSWORD): string
{
    $path = $directory.'/fixture-'.Str::random(8).'.zip';
    $type = $tables === [] ? 'config' : 'full';

    $manifest = array_merge([
        'schema' => 1,
        'type' => $type,
        'app' => 'private-showing',
        'created_at' => now()->utc()->format('Y-m-d\TH:i:s\Z'),
        'last_migration' => DB::table('migrations')->orderByDesc('id')->value('migration'),
        'tables' => (object) array_map('count', $tables),
    ], $manifest);

    $entries = [
        'manifest.json' => json_encode($manifest),
        'config/integration_settings.json' => json_encode((object) $config),
    ];

    foreach ($tables as $table => $rows) {
        $entries["data/{$table}.jsonl"] = collect($rows)->map(fn (array $row): string => json_encode($row)."\n")->implode('');
    }

    $zip = new ZipArchive;
    $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);

    foreach ($entries as $name => $body) {
        $zip->addFromString($name, $body);
        $zip->setEncryptionName($name, ZipArchive::EM_AES_256, $password);
    }

    $zip->close();

    return $path;
}

/**
 * @return list<array<string, mixed>>
 */
function restorerRows(string $table): array
{
    return DB::table($table)->orderBy('id')->get()->map(fn (object $row): array => (array) $row)->all();
}

test('a wrong password throws and changes nothing', function () {
    Title::factory()->create(['name' => 'Keep Me']);
    $zip = restorerFixtureZip($this->backupDirectory, ['titles' => []], ['plex.url' => 'http://new'], password: 'some-other-password');

    expect(fn () => app(BackupRestorer::class)->restore($zip))->toThrow(BackupException::class, 'Wrong password or not a valid backup');

    expect(Title::pluck('name')->all())->toBe(['Keep Me'])
        ->and($this->settings->get('plex.url'))->toBeNull()
        ->and(glob($this->backupDirectory.'/screening-room-*.zip'))->toBe([]);
});

test('a backup from newer code is refused', function () {
    Title::factory()->create();
    $zip = restorerFixtureZip($this->backupDirectory, ['titles' => []], manifest: ['last_migration' => '2099_01_01_000000_from_the_future']);

    expect(fn () => app(BackupRestorer::class)->restore($zip))->toThrow(BackupException::class, 'newer version');

    expect(Title::count())->toBe(1)
        ->and(glob($this->backupDirectory.'/screening-room-*.zip'))->toBe([]);
});

test('a config-only restore sets settings and leaves data alone', function () {
    Title::factory()->create(['name' => 'Untouched']);
    $this->settings->set('plex.url', 'http://old');
    $this->settings->set('sonarr.url', 'http://kept');

    $zip = restorerFixtureZip($this->backupDirectory, config: [
        'plex.url' => 'http://restored',
        'seerr.api_key' => 'abc',
        'backup.password' => 'a-password-from-elsewhere',
        'backup.path' => '/somewhere/else',
    ]);

    $result = app(BackupRestorer::class)->restore($zip);

    $settings = app(IntegrationSettings::class);

    expect($result->type)->toBe(BackupType::Config)
        ->and($result->tables)->toBe([])
        ->and($result->configKeys)->toBe(2)
        ->and($settings->get('plex.url'))->toBe('http://restored')
        ->and($settings->get('seerr.api_key'))->toBe('abc')
        ->and($settings->get('sonarr.url'))->toBe('http://kept')
        ->and($settings->get('backup.password'))->toBe(RESTORER_PASSWORD)
        ->and($settings->get('backup.path'))->toBe($this->backupDirectory)
        ->and(Title::pluck('name')->all())->toBe(['Untouched']);
});

test('a full restore replaces the table rows exactly', function () {
    $wanted = Title::factory()->count(2)->create();
    $wantedRows = restorerRows('titles');
    Title::query()->delete();
    Title::factory()->create(['name' => 'Should Be Gone']);

    $zip = restorerFixtureZip($this->backupDirectory, ['titles' => $wantedRows], ['plex.url' => 'http://restored']);

    $result = app(BackupRestorer::class)->restore($zip);

    expect(restorerRows('titles'))->toEqual($wantedRows)
        ->and($result->type)->toBe(BackupType::Full)
        ->and($result->tables)->toBe(['titles' => 2])
        ->and($result->configKeys)->toBe(1)
        ->and(Title::whereIn('id', $wanted->modelKeys())->count())->toBe(2);
});

test('unknown columns, missing tables and excluded tables are skipped', function () {
    $title = Title::factory()->create();
    $row = (array) DB::table('titles')->first();
    $migrationCount = DB::table('migrations')->count();
    $row['column_from_the_future'] = 'ignored';
    Title::query()->delete();

    $zip = restorerFixtureZip($this->backupDirectory, [
        'titles' => [$row],
        'table_from_the_future' => [['id' => 1]],
        'migrations' => [['id' => 9999, 'migration' => 'bogus', 'batch' => 1]],
    ]);

    $result = app(BackupRestorer::class)->restore($zip);

    expect(Title::pluck('id')->all())->toBe([$title->id])
        ->and($result->tables)->toBe(['titles' => 1])
        ->and($result->skippedTables)->toEqualCanonicalizing(['table_from_the_future', 'migrations'])
        ->and(DB::table('migrations')->count())->toBe($migrationCount);
});

test('a safety backup of the current state is created first when users exist', function () {
    User::factory()->create();
    Title::factory()->create(['name' => 'Before Restore']);
    $zip = restorerFixtureZip($this->backupDirectory, ['titles' => []]);

    $result = app(BackupRestorer::class)->restore($zip);

    expect($result->safetyBackup)->not->toBeNull();

    $safety = app(BackupManager::class)->path($result->safetyBackup);

    $archive = new ZipArchive;
    $archive->open($safety);
    $archive->setPassword(RESTORER_PASSWORD);
    $titles = collect(explode("\n", trim($archive->getFromName('data/titles.jsonl'))))->map(fn (string $line): array => json_decode($line, true));
    $archive->close();

    expect(Title::count())->toBe(0)
        ->and($titles->pluck('name')->all())->toBe(['Before Restore']);
});

test('the restore is aborted when the safety backup cannot be made and users exist', function () {
    User::factory()->create();
    Title::factory()->create(['name' => 'Keep Me']);
    $zip = restorerFixtureZip($this->backupDirectory, ['titles' => []], password: 'explicit-password');
    $this->settings->forget('backup.password');

    expect(fn () => app(BackupRestorer::class)->restore($zip, 'explicit-password'))->toThrow(BackupException::class);

    expect(Title::pluck('name')->all())->toBe(['Keep Me']);
});

test('no safety backup is created on a fresh install with no users', function () {
    expect(User::query()->exists())->toBeFalse();

    $this->settings->forget('backup.password');
    $wanted = Title::factory()->count(2)->create();
    $wantedRows = restorerRows('titles');
    Title::query()->delete();

    $zip = restorerFixtureZip($this->backupDirectory, ['titles' => $wantedRows], password: 'explicit-password');

    $result = app(BackupRestorer::class)->restore($zip, 'explicit-password');

    expect($result->safetyBackup)->toBeNull()
        ->and(restorerRows('titles'))->toEqual($wantedRows)
        ->and(glob($this->backupDirectory.'/screening-room-*.zip'))->toBe([]);
});

test('caches are bumped after a restore', function () {
    Cache::put('qbittorrent:sid', 'stale-session', 60);
    $watchVersion = app(WatchCacheVersion::class)->current();
    $statsVersion = app(StatsCacheVersion::class)->current();

    app(BackupRestorer::class)->restore(restorerFixtureZip($this->backupDirectory, ['titles' => []]));

    expect(Cache::has('qbittorrent:sid'))->toBeFalse()
        ->and(app(WatchCacheVersion::class)->current())->toBe($watchVersion + 1)
        ->and(app(StatsCacheVersion::class)->current())->toBe($statsVersion + 1);

    Queue::assertPushed(WarmStatsCache::class);
});

test('a backup made by BackupManager restores the original state', function () {
    User::factory()->create(['email' => 'original@example.com']);
    Title::factory()->count(3)->create();
    $this->settings->set('plex.url', 'http://original');

    $usersBefore = restorerRows('users');
    $titlesBefore = restorerRows('titles');

    $backup = app(BackupManager::class)->create(BackupType::Full);

    Title::query()->delete();
    Title::factory()->create(['name' => 'Added After Backup']);
    User::query()->delete();
    $this->settings->set('plex.url', 'http://changed');
    IntegrationSetting::create(['key' => 'sonarr.url', 'value' => 'http://added-later']);

    $this->travel(5)->seconds();

    $result = app(BackupRestorer::class)->restore(app(BackupManager::class)->path($backup->filename));

    expect(restorerRows('users'))->toEqual($usersBefore)
        ->and(restorerRows('titles'))->toEqual($titlesBefore)
        ->and(app(IntegrationSettings::class)->get('plex.url'))->toBe('http://original')
        ->and(app(IntegrationSettings::class)->get('sonarr.url'))->toBe('http://added-later')
        ->and($result->safetyBackup)->not->toBe($backup->filename);
});

test('the command asks for confirmation unless forced', function () {
    Title::factory()->create(['name' => 'Keep Me']);
    $zip = restorerFixtureZip($this->backupDirectory, ['titles' => []]);
    $question = 'This replaces ALL current data and settings with the backup (a safety backup is made first). Continue?';

    $this->artisan('backup:restore', ['file' => $zip])
        ->expectsConfirmation($question, 'no')
        ->assertSuccessful();

    expect(Title::pluck('name')->all())->toBe(['Keep Me']);

    $this->artisan('backup:restore', ['file' => $zip])
        ->expectsConfirmation($question, 'yes')
        ->assertSuccessful();

    expect(Title::count())->toBe(0);
});

test('the command restores with --force and reports a wrong password', function () {
    Title::factory()->create(['name' => 'Keep Me']);
    $zip = restorerFixtureZip($this->backupDirectory, ['titles' => []], password: 'another-password');

    $this->artisan('backup:restore', ['file' => $zip, '--force' => true])
        ->expectsOutputToContain('Wrong password or not a valid backup')
        ->assertFailed();

    expect(Title::count())->toBe(1);

    $this->artisan('backup:restore', ['file' => $zip, '--force' => true, '--password' => 'another-password'])
        ->assertSuccessful();

    expect(Title::count())->toBe(0);
});

test('old private-showing backups can be restored', function () {
    $wanted = Title::factory()->count(2)->create();
    $wantedRows = restorerRows('titles');
    Title::query()->delete();

    $zip = restorerFixtureZip($this->backupDirectory, ['titles' => $wantedRows], manifest: ['app' => 'private-showing']);

    $result = app(BackupRestorer::class)->restore($zip);

    expect(restorerRows('titles'))->toEqual($wantedRows)
        ->and($result->type)->toBe(BackupType::Full)
        ->and($result->tables)->toBe(['titles' => 2]);
});

test('new screening-room backups can be restored', function () {
    $wanted = Title::factory()->count(2)->create();
    $wantedRows = restorerRows('titles');
    Title::query()->delete();

    $zip = restorerFixtureZip($this->backupDirectory, ['titles' => $wantedRows], manifest: ['app' => 'screening-room']);

    $result = app(BackupRestorer::class)->restore($zip);

    expect(restorerRows('titles'))->toEqual($wantedRows)
        ->and($result->type)->toBe(BackupType::Full)
        ->and($result->tables)->toBe(['titles' => 2]);
});

test('a backup with an unknown app name is rejected', function () {
    Title::factory()->create();
    $zip = restorerFixtureZip($this->backupDirectory, ['titles' => []], manifest: ['app' => 'unknown-app']);

    expect(fn () => app(BackupRestorer::class)->restore($zip))->toThrow(BackupException::class, 'Screening Room backup');
});
