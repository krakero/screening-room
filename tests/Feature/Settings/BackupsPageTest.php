<?php

use App\Enums\BackupType;
use App\Models\User;
use App\Services\Backup\BackupException;
use App\Services\Backup\BackupFile;
use App\Services\Backup\BackupManager;
use App\Services\Backup\BackupRestorer;
use App\Services\Backup\RestoreResult;
use App\Support\IntegrationSettings;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Livewire\Livewire;
use Mockery\MockInterface;

function backupFile(string $filename = 'screening-room-full-2026-09-28_120000.zip', BackupType $type = BackupType::Full): BackupFile
{
    return new BackupFile($filename, $type, 2048, CarbonImmutable::parse('2026-09-28 12:00:00'));
}

function mockBackupManager(array $files = []): MockInterface
{
    $manager = Mockery::mock(BackupManager::class);
    $manager->shouldReceive('all')->andReturn(collect($files));
    app()->instance(BackupManager::class, $manager);

    return $manager;
}

function configureBackupPassword(): void
{
    app(IntegrationSettings::class)->set('backup.password', 'correct-horse');
}

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

test('guests are redirected to the login page', function () {
    auth()->logout();

    $this->get(route('settings.backups'))->assertRedirect(route('login'));
});

test('the backups page can be rendered', function () {
    mockBackupManager();

    $this->get(route('settings.backups'))
        ->assertOk()
        ->assertSee('Backups')
        ->assertSee('No backups yet')
        ->assertDontSee('@if');
});

test('saving stores the password and folder, keeping the password when left blank', function () {
    mockBackupManager();
    $settings = app(IntegrationSettings::class);

    Livewire::test('pages::settings.backups')
        ->set('backupPassword', 'correct-horse')
        ->set('backupFolder', '/srv/backups/')
        ->call('saveBackupSettings')
        ->assertHasNoErrors()
        ->assertSet('backupPassword', '')
        ->assertSet('backupPasswordConfigured', true)
        ->assertSet('backupFolder', '/srv/backups')
        ->call('saveBackupSettings')
        ->assertHasNoErrors();

    expect($settings->get('backup.password'))->toBe('correct-horse')
        ->and($settings->get('backup.path'))->toBe('/srv/backups');
});

test('the saved password is never rendered', function () {
    mockBackupManager();
    configureBackupPassword();

    Livewire::test('pages::settings.backups')
        ->assertDontSee('correct-horse')
        ->assertSet('backupPassword', '');
});

test('a short password and a relative folder are rejected', function () {
    mockBackupManager();

    Livewire::test('pages::settings.backups')
        ->set('backupPassword', 'short')
        ->set('backupFolder', 'relative/path')
        ->call('saveBackupSettings')
        ->assertHasErrors(['backupPassword', 'backupFolder']);

    expect(app(IntegrationSettings::class)->configured('backup.password'))->toBeFalse();
});

test('clearing the folder falls back to the default', function () {
    mockBackupManager();
    app(IntegrationSettings::class)->set('backup.path', '/srv/backups');

    Livewire::test('pages::settings.backups')
        ->set('backupFolder', '')
        ->call('saveBackupSettings');

    expect(app(IntegrationSettings::class)->configured('backup.path'))->toBeFalse();
});

test('the backup buttons are disabled and do nothing without a saved password', function () {
    $manager = mockBackupManager();
    $manager->shouldNotReceive('create');

    Livewire::test('pages::settings.backups')
        ->assertSeeHtml('disabled')
        ->call('backUpNow')
        ->call('backUpConfigOnly');
});

test('the backup buttons are enabled once a password is saved', function () {
    mockBackupManager();
    configureBackupPassword();

    Livewire::test('pages::settings.backups')
        ->assertDontSeeHtml('disabled=""');
});

test('back up now creates a full backup and toasts the filename', function () {
    configureBackupPassword();
    $manager = mockBackupManager();
    $manager->shouldReceive('create')->once()->with(BackupType::Full)->andReturn(backupFile());

    Livewire::test('pages::settings.backups')
        ->call('backUpNow')
        ->assertDispatched('toast-show', fn (string $event, array $params) => str_contains($params['slots']['text'], 'screening-room-full-2026-09-28_120000.zip'));
});

test('back up config only creates a config backup', function () {
    configureBackupPassword();
    $manager = mockBackupManager();
    $manager->shouldReceive('create')->once()->with(BackupType::Config)->andReturn(backupFile('screening-room-config-2026-09-28_120000.zip', BackupType::Config));

    Livewire::test('pages::settings.backups')->call('backUpConfigOnly');
});

test('a backup failure shows the message', function () {
    configureBackupPassword();
    $manager = mockBackupManager();
    $manager->shouldReceive('create')->andThrow(BackupException::folderNotWritable('/nope'));

    Livewire::test('pages::settings.backups')
        ->call('backUpNow')
        ->assertDispatched('toast-show', fn (string $event, array $params) => str_contains($params['slots']['text'], '/nope'));
});

test('existing backups are listed with type, size and date', function () {
    mockBackupManager([
        backupFile(),
        backupFile('screening-room-config-2026-09-27_080000.zip', BackupType::Config),
    ]);

    $this->get(route('settings.backups'))
        ->assertOk()
        ->assertSee('screening-room-full-2026-09-28_120000.zip')
        ->assertSee('screening-room-config-2026-09-27_080000.zip')
        ->assertSee('Config only')
        ->assertSee('2 KB')
        ->assertSee(route('settings.backups.download', 'screening-room-full-2026-09-28_120000.zip'), false);
});

describe('downloads', function () {
    beforeEach(function () {
        $this->folder = sys_get_temp_dir().'/backups-page-'.uniqid();
        File::ensureDirectoryExists($this->folder);
        File::put($this->folder.'/screening-room-full-2026-09-28_120000.zip', 'zip-bytes');
        File::put($this->folder.'/secret.txt', 'secret');
        app(IntegrationSettings::class)->set('backup.path', $this->folder);
    });

    afterEach(function () {
        File::deleteDirectory($this->folder);
    });

    test('a backup can be downloaded', function () {
        $this->get(route('settings.backups.download', 'screening-room-full-2026-09-28_120000.zip'))
            ->assertOk()
            ->assertDownload('screening-room-full-2026-09-28_120000.zip');
    });

    test('path traversal and non-backup files are rejected', function () {
        $this->get('/settings/backups/'.rawurlencode('../secret.txt').'/download')->assertNotFound();
        $this->get(route('settings.backups.download', 'secret.txt'))->assertNotFound();
        $this->get(route('settings.backups.download', 'screening-room-full-2000-01-01_000000.zip'))->assertNotFound();
    });

    test('guests cannot download', function () {
        auth()->logout();

        $this->get(route('settings.backups.download', 'screening-room-full-2026-09-28_120000.zip'))
            ->assertRedirect(route('login'));
    });
});

test('deleting a backup asks for confirmation then deletes it', function () {
    $manager = mockBackupManager([backupFile()]);
    $manager->shouldReceive('delete')->once()->with('screening-room-full-2026-09-28_120000.zip');

    Livewire::test('pages::settings.backups')
        ->call('confirmDelete', 'screening-room-full-2026-09-28_120000.zip')
        ->assertSet('showDeleteModal', true)
        ->assertSet('deletingFilename', 'screening-room-full-2026-09-28_120000.zip')
        ->call('deleteBackup')
        ->assertSet('showDeleteModal', false)
        ->assertSet('deletingFilename', '');
});

test('restoring an existing backup calls the restorer with the chosen file and password', function () {
    $manager = mockBackupManager([backupFile()]);
    $manager->shouldReceive('path')->with('private-showing-config-2026-09-27_080000.zip')->andReturn('/backups/private-showing-config-2026-09-27_080000.zip');

    $restorer = Mockery::mock(BackupRestorer::class);
    $restorer->shouldReceive('restore')
        ->once()
        ->with('/backups/private-showing-config-2026-09-27_080000.zip', 'other-password')
        ->andReturn(new RestoreResult(BackupType::Config, [], 4, 'screening-room-full-safety.zip'));
    app()->instance(BackupRestorer::class, $restorer);

    Livewire::test('pages::settings.backups')
        ->set('restoreFilename', 'private-showing-config-2026-09-27_080000.zip')
        ->set('restorePassword', 'other-password')
        ->call('confirmRestore')
        ->assertSet('showRestoreModal', true)
        ->call('restore')
        ->assertSet('showRestoreModal', false)
        ->assertSee('screening-room-full-safety.zip')
        ->assertSee('Integration settings: 4');

    $this->assertAuthenticated();
});

test('restoring an uploaded file defaults to the saved password', function () {
    configureBackupPassword();
    mockBackupManager();

    $restorer = Mockery::mock(BackupRestorer::class);
    $restorer->shouldReceive('restore')
        ->once()
        ->withArgs(fn (string $path, ?string $password) => is_file($path) && $password === null)
        ->andReturn(new RestoreResult(BackupType::Config, [], 1, 'safety.zip'));
    app()->instance(BackupRestorer::class, $restorer);

    Livewire::test('pages::settings.backups')
        ->set('restoreFile', UploadedFile::fake()->create('backup.zip', 10, 'application/zip'))
        ->call('confirmRestore')
        ->assertHasNoErrors()
        ->call('restore')
        ->assertSet('restoreFile', null)
        ->assertSee('safety.zip');
});

test('a full restore that replaces users logs out and redirects to login', function () {
    $manager = mockBackupManager([backupFile()]);
    $manager->shouldReceive('path')->andReturn('/backups/full.zip');

    $restorer = Mockery::mock(BackupRestorer::class);
    $restorer->shouldReceive('restore')->andReturn(new RestoreResult(BackupType::Full, ['users' => 1, 'titles' => 5], 2, 'safety.zip'));
    app()->instance(BackupRestorer::class, $restorer);

    Livewire::test('pages::settings.backups')
        ->set('restoreFilename', 'screening-room-full-2026-09-28_120000.zip')
        ->set('restorePassword', 'correct-horse')
        ->call('confirmRestore')
        ->call('restore')
        ->assertRedirect(route('login'));

    $this->assertGuest();
    expect(session('status'))->toContain('safety.zip');
});

test('a restore error shows the message and keeps the session', function () {
    $manager = mockBackupManager([backupFile()]);
    $manager->shouldReceive('path')->andReturn('/backups/full.zip');

    $restorer = Mockery::mock(BackupRestorer::class);
    $restorer->shouldReceive('restore')->andThrow(new BackupException('Wrong password or not a valid backup'));
    app()->instance(BackupRestorer::class, $restorer);

    Livewire::test('pages::settings.backups')
        ->set('restoreFilename', 'screening-room-full-2026-09-28_120000.zip')
        ->set('restorePassword', 'wrong-password')
        ->call('confirmRestore')
        ->call('restore')
        ->assertHasErrors('restore')
        ->assertSee('Wrong password or not a valid backup');

    $this->assertAuthenticated();
});

test('restoring requires choosing a backup and a password', function () {
    mockBackupManager();

    Livewire::test('pages::settings.backups')
        ->call('confirmRestore')
        ->assertHasErrors('restoreFilename')
        ->assertSet('showRestoreModal', false)
        ->set('restoreFile', UploadedFile::fake()->create('backup.zip', 10))
        ->call('confirmRestore')
        ->assertHasErrors('restorePassword')
        ->assertSet('showRestoreModal', false);
});

test('large backup uploads up to 512 MB are allowed', function () {
    configureBackupPassword();
    mockBackupManager();

    Livewire::test('pages::settings.backups')
        ->set('restoreFile', UploadedFile::fake()->create('large-backup.zip', 102400, 'application/zip'))
        ->call('confirmRestore')
        ->assertHasNoErrors('restoreFile')
        ->assertSet('showRestoreModal', true);
});
