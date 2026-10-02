<?php

use App\Enums\BackupType;
use App\Services\Backup\BackupException;
use App\Services\Backup\BackupFile;
use App\Services\Backup\BackupManager;
use App\Services\Backup\BackupRestorer;
use App\Support\DisplayTimezone;
use App\Support\IntegrationSettings;
use Flux\Flux;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;

new #[Title('Backups')] class extends Component
{
    use WithFileUploads;

    public string $backupPassword = '';

    public bool $backupPasswordConfigured = false;

    public string $backupFolder = '';

    public bool $showDeleteModal = false;

    #[Locked]
    public string $deletingFilename = '';

    public string $restoreFilename = '';

    public ?UploadedFile $restoreFile = null;

    public string $restorePassword = '';

    public bool $showRestoreModal = false;

    /** @var array{type: string, tables: array<string, int>, configKeys: int, safetyBackup: string}|null */
    #[Locked]
    public ?array $restoreSummary = null;

    public function mount(IntegrationSettings $settings): void
    {
        $this->backupPasswordConfigured = $settings->configured('backup.password');
        $this->backupFolder = (string) $settings->get('backup.path', '');
    }

    /**
     * @return Collection<int, BackupFile>
     */
    public function getBackupsProperty(): Collection
    {
        return app(BackupManager::class)->all();
    }

    public function getDefaultFolderProperty(): string
    {
        return storage_path('app/backups');
    }

    public function saveBackupSettings(IntegrationSettings $settings): void
    {
        $validated = $this->validate([
            'backupPassword' => ['nullable', 'string', 'min:8'],
            'backupFolder' => ['nullable', 'string', 'max:500', 'starts_with:/'],
        ]);

        if (filled($validated['backupPassword'])) {
            $settings->set('backup.password', $validated['backupPassword']);
            $this->backupPasswordConfigured = true;
        }

        if (filled($validated['backupFolder'])) {
            $settings->set('backup.path', rtrim($validated['backupFolder'], '/'));
        } else {
            $settings->forget('backup.path');
        }

        $this->backupPassword = '';
        $this->backupFolder = (string) $settings->get('backup.path', '');

        Flux::toast(variant: 'success', text: __('Backup settings saved.'));
    }

    public function backUpNow(): void
    {
        $this->createBackup(BackupType::Full);
    }

    public function backUpConfigOnly(): void
    {
        $this->createBackup(BackupType::Config);
    }

    private function createBackup(BackupType $type): void
    {
        if (! $this->backupPasswordConfigured) {
            Flux::toast(variant: 'danger', text: __('Save a backup password first.'));

            return;
        }

        try {
            $file = app(BackupManager::class)->create($type);
        } catch (BackupException $e) {
            Flux::toast(variant: 'danger', text: $e->getMessage());

            return;
        }

        Flux::toast(variant: 'success', text: __('Backup created: :filename', ['filename' => $file->filename]));
    }

    public function confirmDelete(string $filename): void
    {
        $this->deletingFilename = $filename;
        $this->showDeleteModal = true;
    }

    public function deleteBackup(): void
    {
        try {
            app(BackupManager::class)->delete($this->deletingFilename);
        } catch (BackupException $e) {
            Flux::toast(variant: 'danger', text: $e->getMessage());
            $this->closeDeleteModal();

            return;
        }

        $this->closeDeleteModal();

        Flux::toast(variant: 'success', text: __('Backup deleted.'));
    }

    public function closeDeleteModal(): void
    {
        $this->showDeleteModal = false;
        $this->deletingFilename = '';
    }

    public function confirmRestore(): void
    {
        $this->validate([
            'restoreFilename' => ['nullable', 'string'],
            'restoreFile' => ['nullable', 'file', 'extensions:zip', 'max:524288'],
            'restorePassword' => ['nullable', 'string'],
        ]);

        if ($this->restoreFile === null && $this->restoreFilename === '') {
            $this->addError('restoreFilename', __('Choose a backup or upload a .zip to restore.'));

            return;
        }

        if ($this->restorePassword === '' && ! $this->backupPasswordConfigured) {
            $this->addError('restorePassword', __('Enter the backup password.'));

            return;
        }

        $this->restoreSummary = null;
        $this->showRestoreModal = true;
    }

    public function restore(): void
    {
        $this->showRestoreModal = false;

        try {
            $path = $this->restoreFile !== null
                ? $this->restoreFile->getRealPath()
                : app(BackupManager::class)->path($this->restoreFilename);

            $result = app(BackupRestorer::class)->restore($path, $this->restorePassword ?: null);
        } catch (BackupException $e) {
            $this->restoreSummary = null;
            $this->addError('restore', $e->getMessage());

            return;
        }

        $this->reset('restoreFile', 'restoreFilename', 'restorePassword');

        $this->restoreSummary = [
            'type' => $result->type->value,
            'tables' => $result->tables,
            'configKeys' => $result->configKeys,
            'safetyBackup' => $result->safetyBackup,
        ];

        if ($result->type === BackupType::Full && array_key_exists('users', $result->tables)) {
            Auth::logout();
            session()->invalidate();
            session()->regenerateToken();

            $status = $result->safetyBackup !== null
                ? __('Backup restored. A safety backup of the previous data was saved as :filename. Please sign in again.', ['filename' => $result->safetyBackup])
                : __('Backup restored. Please sign in again.');

            session()->flash('status', $status);

            $this->redirectRoute('login');

            return;
        }

        Flux::toast(variant: 'success', text: __('Backup restored.'));
    }

    public function closeRestoreModal(): void
    {
        $this->showRestoreModal = false;
    }
}; ?>

<section class="w-full">
    @include('partials.settings-heading')

    <flux:heading level="2" class="sr-only">{{ __('Backups') }}</flux:heading>

    <x-pages::settings.layout :heading="__('Backups')" :subheading="__('Password-protected backups of your data and integration settings')">
        <form wire:submit="saveBackupSettings" class="space-y-6">
            <x-settings.masked-input wire:model="backupPassword" :label="__('Backup password')" :configured="$backupPasswordConfigured" :description="__('At least 8 characters. Needed to create and restore backups, so keep a copy somewhere safe.')" />
            <flux:input wire:model="backupFolder" :label="__('Folder')" :placeholder="$this->defaultFolder" :description="__('Absolute path where backups are stored. Leave blank to use the default.')" />

            <flux:button variant="primary" type="submit">{{ __('Save') }}</flux:button>
        </form>

        <div class="mt-8 flex flex-wrap items-center gap-3">
            <flux:button variant="primary" icon="archive-box-arrow-down" wire:click="backUpNow" :disabled="! $backupPasswordConfigured">{{ __('Back up now') }}</flux:button>
            <flux:button wire:click="backUpConfigOnly" :disabled="! $backupPasswordConfigured">{{ __('Back up config only') }}</flux:button>
        </div>

        <div class="mt-8 overflow-hidden rounded-lg border border-line">
            @forelse ($this->backups as $backup)
                <div wire:key="backup-{{ $backup->filename }}" class="flex items-center justify-between gap-3 p-4 {{ ! $loop->last ? 'border-b border-line' : '' }}">
                    <div class="min-w-0 space-y-1">
                        <p class="truncate text-sm font-medium text-ink">{{ $backup->filename }}</p>
                        <p class="flex flex-wrap items-center gap-2 text-xs text-ink-muted">
                            <flux:badge size="sm">{{ $backup->type->label() }}</flux:badge>
                            <span>{{ Number::fileSize($backup->size) }}</span>
                            <span class="opacity-50">/</span>
                            <span>{{ DisplayTimezone::local($backup->createdAt)->format('M j, Y g:ia') }}</span>
                        </p>
                    </div>

                    <div class="flex shrink-0 items-center gap-1">
                        <flux:button size="sm" variant="ghost" icon="arrow-down-tray" :href="route('settings.backups.download', $backup->filename)" :aria-label="__('Download')" />
                        <flux:button size="sm" variant="ghost" icon="trash" wire:click="confirmDelete('{{ $backup->filename }}')" :aria-label="__('Delete')" class="text-red-500 hover:text-red-600" />
                    </div>
                </div>
            @empty
                <div class="p-8 text-center">
                    <p class="font-medium text-ink">{{ __('No backups yet') }}</p>
                    <flux:text class="mt-1">{{ __('Save a password, then back up now.') }}</flux:text>
                </div>
            @endforelse
        </div>

        <div class="mt-10 space-y-6">
            <div>
                <flux:heading>{{ __('Restore') }}</flux:heading>
                <flux:text size="sm" class="mt-1">{{ __('Replaces all current data with the backup. A safety backup of the current state is made first.') }}</flux:text>
            </div>

            <form wire:submit="confirmRestore" class="space-y-6">
                <flux:field>
                    <flux:label>{{ __('Existing backup') }}</flux:label>
                    <flux:select wire:model="restoreFilename" :placeholder="__('Choose a backup…')">
                        @foreach ($this->backups as $backup)
                            <flux:select.option :value="$backup->filename" wire:key="restore-{{ $backup->filename }}">{{ $backup->filename }}</flux:select.option>
                        @endforeach
                    </flux:select>
                    <flux:error name="restoreFilename" />
                </flux:field>

                <flux:input wire:model="restoreFile" type="file" accept=".zip" :label="__('Or upload a backup')" :description="__('A .zip up to 512 MB. An uploaded file is used instead of the selection above.')" />

                <flux:input wire:model="restorePassword" type="password" viewable :label="__('Password')" :description="__('Leave blank to use the saved backup password.')" />
                <flux:error name="restorePassword" />
                <flux:error name="restore" />

                <flux:button variant="danger" type="submit">{{ __('Restore…') }}</flux:button>
            </form>

            @if ($restoreSummary)
                <flux:card variant="outline" :highlight="false" class="border-line bg-surface">
                    @if ($restoreSummary['safetyBackup'] !== null)
                        <flux:text>{{ __('Restored a :type backup. Your previous data was saved as :filename.', ['type' => $restoreSummary['type'], 'filename' => $restoreSummary['safetyBackup']]) }}</flux:text>
                    @else
                        <flux:text>{{ __('Restored a :type backup.', ['type' => $restoreSummary['type']]) }}</flux:text>
                    @endif
                    <ul class="mt-2 space-y-1 text-sm text-ink-muted">
                        @foreach ($restoreSummary['tables'] as $table => $rows)
                            <li>{{ $table }}: {{ $rows }}</li>
                        @endforeach
                        <li>{{ __('Integration settings: :count', ['count' => $restoreSummary['configKeys']]) }}</li>
                    </ul>
                </flux:card>
            @endif
        </div>
    </x-pages::settings.layout>

    <flux:modal name="delete-backup-modal" class="max-w-md md:min-w-md" @close="closeDeleteModal" wire:model="showDeleteModal">
        <div class="space-y-6">
            <div class="space-y-2">
                <flux:heading size="lg">{{ __('Delete backup') }}</flux:heading>
                <flux:text>{{ __('Delete ":filename"? This cannot be undone.', ['filename' => $deletingFilename]) }}</flux:text>
            </div>

            <div class="flex justify-end gap-3">
                <flux:button variant="outline" wire:click="closeDeleteModal">{{ __('Cancel') }}</flux:button>
                <flux:button variant="danger" wire:click="deleteBackup">{{ __('Delete') }}</flux:button>
            </div>
        </div>
    </flux:modal>

    <flux:modal name="restore-backup-modal" class="max-w-md md:min-w-md" @close="closeRestoreModal" wire:model="showRestoreModal">
        <div class="space-y-6">
            <div class="space-y-2">
                <flux:heading size="lg">{{ __('Restore backup') }}</flux:heading>
                <flux:text>{{ __('This replaces ALL current data with the backup. A safety backup of the current state is made first, and its name is shown when the restore finishes. A full restore signs you out.') }}</flux:text>
            </div>

            <div class="flex justify-end gap-3">
                <flux:button variant="outline" wire:click="closeRestoreModal">{{ __('Cancel') }}</flux:button>
                <flux:button variant="danger" wire:click="restore">{{ __('Restore everything') }}</flux:button>
            </div>
        </div>
    </flux:modal>
</section>
