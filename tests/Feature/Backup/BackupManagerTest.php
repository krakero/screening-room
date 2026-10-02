<?php

use App\Enums\BackupType;
use App\Models\Title;
use App\Models\User;
use App\Services\Backup\BackupException;
use App\Services\Backup\BackupManager;
use App\Support\IntegrationSettings;
use Illuminate\Support\Facades\File;

beforeEach(function () {
    $this->backupDirectory = sys_get_temp_dir().'/ps-backups-'.uniqid();

    $settings = app(IntegrationSettings::class);
    $settings->set('backup.path', $this->backupDirectory);
    $settings->set('backup.password', 'correct-horse');
});

afterEach(function () {
    File::deleteDirectory($this->backupDirectory);
});

/**
 * @return array<string, string> Entry name => decrypted contents.
 */
function readBackup(string $path, string $password = 'correct-horse'): array
{
    $zip = new ZipArchive;
    $zip->open($path);
    $zip->setPassword($password);

    $entries = [];

    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = $zip->getNameIndex($i);
        $entries[$name] = $zip->getFromName($name);
    }

    return $entries;
}

test('it creates an AES encrypted zip that only opens with the password', function () {
    $file = app(BackupManager::class)->create();
    $path = app(BackupManager::class)->path($file->filename);

    $locked = new ZipArchive;
    $locked->open($path);

    expect($locked->getFromName('manifest.json'))->toBeFalse();

    $locked->setPassword('correct-horse');

    expect($locked->getFromName('manifest.json'))->toBeString()
        ->and($locked->statName('manifest.json')['encryption_method'])->toBe(ZipArchive::EM_AES_256)
        ->and($file->type)->toBe(BackupType::Full)
        ->and($file->filename)->toMatch('/^screening-room-full-\d{4}-\d{2}-\d{2}_\d{6}\.zip$/')
        ->and($file->size)->toBeGreaterThan(0);
});

test('the manifest describes the backup', function () {
    $file = app(BackupManager::class)->create();

    $manifest = json_decode(readBackup(app(BackupManager::class)->path($file->filename))['manifest.json'], true);

    expect($manifest)->toMatchArray([
        'schema' => 1,
        'type' => 'full',
        'app' => 'screening-room',
        'last_migration' => DB::table('migrations')->orderByDesc('id')->value('migration'),
    ])
        ->and($manifest['created_at'])->toEndWith('Z')
        ->and($manifest['tables'])->toBeArray();
});

test('the config entry has decrypted values and leaves out machine-local backup settings', function () {
    app(IntegrationSettings::class)->set('plex.token', 'secret-token');
    app(IntegrationSettings::class)->set('discover.providers', ['netflix', 'max']);

    $file = app(BackupManager::class)->create(BackupType::Config);

    $config = json_decode(readBackup(app(BackupManager::class)->path($file->filename))['config/integration_settings.json'], true);

    expect($config)->toMatchArray(['plex.token' => 'secret-token', 'discover.providers' => ['netflix', 'max']])
        ->and($config)->not->toHaveKeys(['backup.password', 'backup.path']);
});

test('a full backup exports every app table as jsonl and skips the excluded tables', function () {
    User::factory()->count(2)->create();
    Title::factory()->count(3)->create();

    $file = app(BackupManager::class)->create();

    $entries = readBackup(app(BackupManager::class)->path($file->filename));
    $manifest = json_decode($entries['manifest.json'], true);

    expect($manifest['tables']['users'])->toBe(2)
        ->and($manifest['tables']['titles'])->toBe(3)
        ->and(array_filter(explode("\n", $entries['data/users.jsonl'])))->toHaveCount(2)
        ->and(json_decode(explode("\n", $entries['data/users.jsonl'])[0], true))->toHaveKey('email');

    foreach (BackupManager::EXCLUDED_TABLES as $table) {
        expect($manifest['tables'])->not->toHaveKey($table)
            ->and($entries)->not->toHaveKey("data/{$table}.jsonl");
    }

    expect($manifest['tables'])->toHaveKeys(['users', 'titles', 'episodes', 'follows', 'plays']);
});

test('a config-only backup has no data entries and no tables', function () {
    User::factory()->create();

    $file = app(BackupManager::class)->create(BackupType::Config);

    $entries = readBackup(app(BackupManager::class)->path($file->filename));
    $manifest = json_decode($entries['manifest.json'], true);

    expect($file->type)->toBe(BackupType::Config)
        ->and($manifest['type'])->toBe('config')
        ->and($manifest['tables'])->toBe([])
        ->and(array_keys($entries))->toEqualCanonicalizing(['manifest.json', 'config/integration_settings.json']);
});

test('creating a backup without a valid password throws', function (?string $password) {
    $password === null
        ? app(IntegrationSettings::class)->forget('backup.password')
        : app(IntegrationSettings::class)->set('backup.password', $password);

    app(BackupManager::class)->create();
})->with([null, 'short'])->throws(BackupException::class);

test('all lists backups newest first and ignores other files', function () {
    File::ensureDirectoryExists($this->backupDirectory);
    touch($this->backupDirectory.'/screening-room-full-2026-01-01_000000.zip');
    touch($this->backupDirectory.'/screening-room-config-2026-03-01_120000.zip');
    touch($this->backupDirectory.'/screening-room-full-2026-02-01_000000.zip');
    touch($this->backupDirectory.'/notes.txt');
    touch($this->backupDirectory.'/other-2026-04-01_000000.zip');

    $files = app(BackupManager::class)->all();

    expect($files->pluck('filename')->all())->toBe([
        'screening-room-config-2026-03-01_120000.zip',
        'screening-room-full-2026-02-01_000000.zip',
        'screening-room-full-2026-01-01_000000.zip',
    ])
        ->and($files->first()->type)->toBe(BackupType::Config)
        ->and($files->first()->createdAt->toIso8601String())->toBe('2026-03-01T12:00:00+00:00');
});

test('all is empty when the folder does not exist', function () {
    expect(app(BackupManager::class)->all())->toBeEmpty();
});

test('all lists both old private-showing and new screening-room backups', function () {
    File::ensureDirectoryExists($this->backupDirectory);
    touch($this->backupDirectory.'/private-showing-full-2026-01-01_000000.zip');
    touch($this->backupDirectory.'/screening-room-config-2026-03-01_120000.zip');
    touch($this->backupDirectory.'/private-showing-config-2026-02-15_080000.zip');
    touch($this->backupDirectory.'/screening-room-full-2026-02-01_000000.zip');

    $files = app(BackupManager::class)->all();

    expect($files->pluck('filename')->all())->toBe([
        'screening-room-config-2026-03-01_120000.zip',
        'private-showing-config-2026-02-15_080000.zip',
        'screening-room-full-2026-02-01_000000.zip',
        'private-showing-full-2026-01-01_000000.zip',
    ]);
});

test('path rejects traversal, foreign names and missing files', function (string $filename) {
    File::ensureDirectoryExists($this->backupDirectory);
    file_put_contents(dirname($this->backupDirectory).'/screening-room-full-2026-01-01_000000.zip', '');

    app(BackupManager::class)->path($filename);
})->with([
    '../screening-room-full-2026-01-01_000000.zip',
    '/etc/passwd',
    'notes.txt',
    'screening-room-full-2026-01-01_000000.zip',
])->throws(BackupException::class);

test('delete removes the backup', function () {
    $file = app(BackupManager::class)->create(BackupType::Config);

    app(BackupManager::class)->delete($file->filename);

    expect(app(BackupManager::class)->all())->toBeEmpty();
});

test('the run command outputs the filename', function () {
    $this->artisan('backup:run', ['--config-only' => true])
        ->expectsOutputToContain('screening-room-config-')
        ->assertExitCode(0);

    expect(app(BackupManager::class)->all())->toHaveCount(1);
});

test('the run command fails without a password', function () {
    app(IntegrationSettings::class)->forget('backup.password');

    $this->artisan('backup:run')->assertExitCode(1);
});

test('two backups in the same second never overwrite each other', function () {
    $this->travelTo(now()->startOfSecond());

    $first = app(BackupManager::class)->create();
    $second = app(BackupManager::class)->create();

    expect($second->filename)->not->toBe($first->filename)
        ->and(app(BackupManager::class)->all())->toHaveCount(2);
});
