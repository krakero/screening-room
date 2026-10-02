<?php

use App\Services\Tmdb\TmdbClient;
use App\Support\IntegrationSettings;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts::setup')] #[Title('Connect TMDB — Setup')] class extends Component
{
    public string $token = '';

    public bool $tokenConfigured = false;

    public function mount(IntegrationSettings $settings): void
    {
        $this->tokenConfigured = $settings->configured('tmdb.token') || filled(config('services.tmdb.token'));
    }

    public function saveAndContinue(IntegrationSettings $settings): void
    {
        $this->validate([
            'token' => [$this->tokenConfigured ? 'nullable' : 'required', 'string'],
        ]);

        $justSet = filled($this->token);

        if ($justSet) {
            $settings->set('tmdb.token', $this->token);
        }

        if (! app(TmdbClient::class)->testConnection()) {
            if ($justSet) {
                $settings->forget('tmdb.token');
            }

            $this->addError('token', __('That token didn\'t work. Double-check it and try again.'));

            return;
        }

        $this->tokenConfigured = true;
        $this->token = '';

        $this->redirectRoute('setup.preferences', navigate: true);
    }
}; ?>

<x-setup.layout step="tmdb" :title="__('Connect TMDB')" :subtitle="__('Screening Room uses The Movie Database for all title metadata, artwork, and search. This is the only required step.')">
    <flux:callout icon="information-circle" class="mb-6">
        <flux:callout.text>
            {{ __('Create a free TMDB account, then grab an "API Read Access Token" from') }}
            <flux:link href="https://www.themoviedb.org/settings/api" target="_blank">themoviedb.org/settings/api</flux:link>.
        </flux:callout.text>
    </flux:callout>

    <form wire:submit="saveAndContinue" class="space-y-6">
        <x-settings.masked-input wire:model="token" :label="__('API Read Access Token')" :configured="$tokenConfigured" autofocus />

        <flux:button type="submit" variant="primary" class="w-full">{{ __('Test & continue') }}</flux:button>
    </form>
</x-setup.layout>
