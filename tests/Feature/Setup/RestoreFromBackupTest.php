<?php

use App\Enums\BackupType;
use App\Models\User;
use App\Services\Backup\BackupManager;
use App\Support\IntegrationSettings;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

use function Pest\Laravel\assertDatabaseCount;

beforeEach(function () {
    // Ensure we start fresh with no users
    User::query()->delete();

    // Set up a backup password for tests
    app(IntegrationSettings::class)->set('backup.password', 'test-password-12345');
});

it('renders the restore page when not set up', function () {
    Livewire::test('pages::setup.restore')
        ->assertStatus(200)
        ->assertSee('Restore from backup');
});

it('redirects to setup.tmdb when a user already exists', function () {
    User::factory()->create();

    Livewire::test('pages::setup.restore')
        ->assertRedirect(route('setup.tmdb'));
});

it('shows error for wrong password', function () {
    $manager = app(BackupManager::class);

    // Create a test backup
    Storage::fake('local');
    $backup = $manager->create(BackupType::Full);
    $backupPath = $manager->path($backup->filename);

    // Copy the backup to the real storage location
    $realBackupPath = storage_path('app/backups/'.$backup->filename);
    if (! is_dir(dirname($realBackupPath))) {
        mkdir(dirname($realBackupPath), 0755, true);
    }
    copy($backupPath, $realBackupPath);

    Livewire::test('pages::setup.restore')
        ->set('selectedBackup', $backup->filename)
        ->set('password', 'wrong-password')
        ->set('confirmed', true)
        ->call('restore')
        ->assertHasErrors('restore');

    // Clean up
    @unlink($realBackupPath);
});

it('successfully restores a full backup and redirects to login', function () {
    $manager = app(BackupManager::class);

    // Create a test user and backup
    $user = User::factory()->create([
        'name' => 'Test User',
        'email' => 'test@example.com',
    ]);

    $backup = $manager->create(BackupType::Full);
    $backupPath = $manager->path($backup->filename);

    // Delete the user to simulate a fresh install (no safety backup should be made)
    User::query()->delete();
    assertDatabaseCount('users', 0);

    Livewire::test('pages::setup.restore')
        ->set('selectedBackup', $backup->filename)
        ->set('password', 'test-password-12345')
        ->set('confirmed', true)
        ->call('restore')
        ->assertRedirect(route('login'))
        ->assertSessionHas('toast', function ($toast) use ($backup) {
            return str_contains($toast['message'], "Restored {$backup->filename}")
                && ! str_contains($toast['message'], 'safety backup');
        });

    // Verify the user was restored
    assertDatabaseCount('users', 1);
    expect(User::first())
        ->email->toBe('test@example.com')
        ->name->toBe('Test User');
});

it('restores from uploaded file', function () {
    $manager = app(BackupManager::class);

    // Create a test user and backup
    $user = User::factory()->create([
        'name' => 'Uploaded User',
        'email' => 'upload@example.com',
    ]);

    $backup = $manager->create(BackupType::Full);
    $backupPath = $manager->path($backup->filename);

    // Delete the user to simulate a fresh install
    User::query()->delete();
    assertDatabaseCount('users', 0);

    // Create an uploaded file from the backup
    $uploadedFile = UploadedFile::fake()->createWithContent(
        $backup->filename,
        file_get_contents($backupPath)
    );

    Livewire::test('pages::setup.restore')
        ->set('uploadedFile', $uploadedFile)
        ->set('password', 'test-password-12345')
        ->set('confirmed', true)
        ->call('restore')
        ->assertRedirect(route('login'));

    // Verify the user was restored
    assertDatabaseCount('users', 1);
    expect(User::first())
        ->email->toBe('upload@example.com')
        ->name->toBe('Uploaded User');
});

it('redirects to setup.account after restoring config-only backup', function () {
    $manager = app(BackupManager::class);

    // Create a user first so we can create a backup
    User::factory()->create();

    // Set a config value
    app(IntegrationSettings::class)->set('test.key', 'test-value');

    // Create a config-only backup
    $backup = $manager->create(BackupType::Config);

    // Delete the user to simulate a fresh install
    User::query()->delete();
    assertDatabaseCount('users', 0);

    Livewire::test('pages::setup.restore')
        ->set('selectedBackup', $backup->filename)
        ->set('password', 'test-password-12345')
        ->set('confirmed', true)
        ->call('restore')
        ->assertRedirect(route('setup.account'));

    // No users should exist after config-only restore
    assertDatabaseCount('users', 0);
});

it('requires confirmation before restoring', function () {
    $manager = app(BackupManager::class);

    User::factory()->create();
    $backup = $manager->create(BackupType::Full);
    User::query()->delete();

    Livewire::test('pages::setup.restore')
        ->set('selectedBackup', $backup->filename)
        ->set('password', 'test-password-12345')
        ->set('confirmed', false)
        ->call('restore')
        ->assertHasErrors('confirmed');
});

it('requires a password', function () {
    $manager = app(BackupManager::class);

    User::factory()->create();
    $backup = $manager->create(BackupType::Full);
    User::query()->delete();

    Livewire::test('pages::setup.restore')
        ->set('selectedBackup', $backup->filename)
        ->set('password', '')
        ->set('confirmed', true)
        ->call('restore')
        ->assertHasErrors('password');
});

it('requires either a selected backup or uploaded file', function () {
    Livewire::test('pages::setup.restore')
        ->set('selectedBackup', null)
        ->set('uploadedFile', null)
        ->set('password', 'test-password-12345')
        ->set('confirmed', true)
        ->call('restore')
        ->assertHasErrors('backup');
});
