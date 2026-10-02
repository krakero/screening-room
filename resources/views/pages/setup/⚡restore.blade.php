<?php

use App\Models\User;
use App\Services\Backup\BackupException;
use App\Services\Backup\BackupFile;
use App\Services\Backup\BackupManager;
use App\Services\Backup\BackupRestorer;
use App\Services\Backup\RestoreResult;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Validate;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

new #[Layout('layouts::setup')] #[Title('Restore from backup')] class extends Component
{
    use WithFileUploads;

    public ?string $selectedBackup = null;

    #[Validate('nullable|file|max:262144')] // 256 MB in KB
    public ?TemporaryUploadedFile $uploadedFile = null;

    public string $password = '';

    public bool $confirmed = false;

    public function mount(): void
    {
        if (User::query()->exists()) {
            $this->redirectRoute('setup.tmdb', navigate: true);
        }
    }

    public function restore(BackupManager $manager, BackupRestorer $restorer): void
    {
        if (User::query()->exists()) {
            $this->redirectRoute('setup.tmdb', navigate: true);

            return;
        }

        if (! $this->confirmed) {
            $this->addError('confirmed', __('Please confirm you want to restore this backup.'));

            return;
        }

        $this->validate();

        if (blank($this->password)) {
            $this->addError('password', __('Password is required.'));

            return;
        }

        try {
            $path = $this->resolveBackupPath($manager);
        } catch (BackupException $e) {
            $this->addError('backup', $e->getMessage());

            return;
        }

        try {
            $result = $restorer->restore($path, $this->password);
        } catch (BackupException $e) {
            $this->addError('restore', $e->getMessage());

            return;
        }

        // Full backup restores users and the setup.finished setting
        if ($result->type->value === 'full' && User::query()->exists()) {
            $message = $result->safetyBackup !== null
                ? __('Restored :backup. A safety backup was saved as :safety.', [
                    'backup' => $this->selectedBackup ?? basename($path),
                    'safety' => $result->safetyBackup,
                ])
                : __('Restored :backup.', [
                    'backup' => $this->selectedBackup ?? basename($path),
                ]);

            session()->flash('toast', [
                'message' => $message,
                'variant' => 'success',
            ]);

            $this->redirectRoute('login', navigate: true);

            return;
        }

        // Config-only backup: no users restored, stay on account creation
        $this->redirectRoute('setup.account', navigate: true);
    }

    /**
     * @return array<int, BackupFile>
     */
    public function backups(): array
    {
        return app(BackupManager::class)->all()->all();
    }

    private function resolveBackupPath(BackupManager $manager): string
    {
        if ($this->uploadedFile !== null) {
            return $this->uploadedFile->getRealPath();
        }

        if (filled($this->selectedBackup)) {
            return $manager->path($this->selectedBackup);
        }

        throw new BackupException(__('Please select a backup file or upload one.'));
    }
}; ?>

<x-setup.layout step="account" :title="__('Restore from backup')" :subtitle="__('Restore your data from a backup file. If you don\'t have one, go back to create a new account.')">
    <div class="space-y-6">
        <div>
            <flux:text size="sm" variant="subtle" class="mb-3">{{ __('Select an existing backup from storage') }}</flux:text>

            @php
                $backupFiles = $this->backups();
            @endphp

            @if (count($backupFiles) > 0)
                <flux:select wire:model="selectedBackup" :placeholder="__('Choose a backup…')">
                    @foreach ($backupFiles as $backup)
                        <flux:select.option value="{{ $backup->filename }}">
                            {{ $backup->filename }} ({{ $backup->type->label() }}, {{ Number::fileSize($backup->size) }})
                        </flux:select.option>
                    @endforeach
                </flux:select>
            @else
                <flux:text size="sm" variant="subtle">{{ __('No backups found in storage.') }}</flux:text>
            @endif
        </div>

        <div class="relative flex items-center gap-3">
            <div class="h-px flex-1 bg-line"></div>
            <flux:text size="sm" variant="subtle">{{ __('or') }}</flux:text>
            <div class="h-px flex-1 bg-line"></div>
        </div>

        <div>
            <flux:text size="sm" variant="subtle" class="mb-3">{{ __('Upload a backup file') }}</flux:text>

            <flux:input
                type="file"
                wire:model="uploadedFile"
                accept=".zip"
                :label="__('Backup file')"
            />

            @error('uploadedFile')
                <flux:text size="sm" class="mt-2 text-red-500">{{ $message }}</flux:text>
            @enderror

            <div wire:loading wire:target="uploadedFile" class="mt-2">
                <flux:text size="sm" variant="subtle">{{ __('Uploading…') }}</flux:text>
            </div>
        </div>

        @error('backup')
            <flux:text size="sm" class="text-red-500">{{ $message }}</flux:text>
        @enderror

        <form wire:submit="restore" class="space-y-6">
            <flux:input
                wire:model="password"
                type="password"
                viewable
                :label="__('Backup password')"
                required
            />

            @error('password')
                <flux:text size="sm" class="text-red-500">{{ $message }}</flux:text>
            @enderror

            <flux:checkbox wire:model="confirmed" :label="__('I understand this will replace all current data')" />

            @error('confirmed')
                <flux:text size="sm" class="text-red-500">{{ $message }}</flux:text>
            @enderror

            @error('restore')
                <flux:text size="sm" class="text-red-500">{{ $message }}</flux:text>
            @enderror

            <flux:button
                type="submit"
                variant="primary"
                class="w-full"
                wire:loading.attr="disabled"
                wire:target="restore"
            >
                <span wire:loading.remove wire:target="restore">{{ __('Restore backup') }}</span>
                <span wire:loading wire:target="restore">{{ __('Restoring…') }}</span>
            </flux:button>
        </form>

        <div class="text-center">
            <flux:link :href="route('setup.account')" wire:navigate class="text-sm text-ink-subtle">{{ __('Back to account creation') }}</flux:link>
        </div>
    </div>
</x-setup.layout>
