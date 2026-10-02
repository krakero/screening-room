<?php

use App\Concerns\TestsIntegrationConnection;
use App\Services\Pushover\PushoverClient;
use App\Support\IntegrationSettings;
use App\Support\SetupProgress;
use Flux\Flux;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts::setup')] #[Title('Notifications — Setup')] class extends Component
{
    use TestsIntegrationConnection;

    public string $userKey = '';

    public string $appToken = '';

    public bool $userKeyConfigured = false;

    public bool $appTokenConfigured = false;

    public function mount(IntegrationSettings $settings, SetupProgress $progress): void
    {
        $this->userKeyConfigured = $settings->configured('pushover.user_key');
        $this->appTokenConfigured = $settings->configured('pushover.app_token');

        $progress->markReached('notifications');
    }

    public function savePushover(IntegrationSettings $settings): void
    {
        $validated = $this->validate([
            'userKey' => ['nullable', 'string'],
            'appToken' => ['nullable', 'string'],
        ]);

        if (filled($validated['userKey'])) {
            $settings->set('pushover.user_key', $validated['userKey']);
            $this->userKeyConfigured = true;
        }

        if (filled($validated['appToken'])) {
            $settings->set('pushover.app_token', $validated['appToken']);
            $this->appTokenConfigured = true;
        }

        $this->userKey = '';
        $this->appToken = '';

        Flux::toast(variant: 'success', text: __('Pushover settings saved.'));
    }

    public function testPushover(): void
    {
        if (! class_exists(PushoverClient::class)) {
            return;
        }

        $this->testConnection(app(PushoverClient::class), __('Pushover'));
    }

    public function continueSetup(): void
    {
        $this->redirectRoute('setup.done', navigate: true);
    }
}; ?>

<x-setup.layout step="notifications" :title="__('Notifications')" :subtitle="__('Optional — get a push notification when a request becomes available.')" back-route="setup.requests" skip-route="setup.done">
    <form wire:submit="savePushover" class="space-y-6">
        <x-settings.masked-input wire:model="userKey" :label="__('User key')" :configured="$userKeyConfigured" />
        <x-settings.masked-input wire:model="appToken" :label="__('Application token')" :configured="$appTokenConfigured" />

        <div class="flex items-center gap-3">
            <flux:button variant="primary" type="submit">{{ __('Save') }}</flux:button>
            <x-settings.test-connection-button :enabled="class_exists(PushoverClient::class)" action="testPushover" />
        </div>
    </form>

    <flux:button variant="primary" class="mt-8 w-full" wire:click="continueSetup">{{ __('Continue') }}</flux:button>
</x-setup.layout>
