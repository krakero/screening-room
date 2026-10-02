<?php

use Flux\Flux;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Devices')] class extends Component
{
    #[Locked]
    public array $tokens = [];

    public bool $showRevokeModal = false;

    #[Locked]
    public ?int $revokingTokenId = null;

    #[Locked]
    public string $revokingTokenName = '';

    public function mount(): void
    {
        $this->loadTokens();
    }

    public function loadTokens(): void
    {
        $this->tokens = auth()->user()->tokens()
            ->latest()
            ->get()
            ->map(fn ($token) => [
                'id' => $token->id,
                'name' => $token->name,
                'created_at_diff' => $token->created_at?->diffForHumans(),
                'last_used_at_diff' => $token->last_used_at?->diffForHumans(),
            ])
            ->toArray();
    }

    public function confirmRevoke(int $tokenId): void
    {
        $token = auth()->user()->tokens()->findOrFail($tokenId);

        $this->revokingTokenId = $token->id;
        $this->revokingTokenName = $token->name;
        $this->showRevokeModal = true;
    }

    public function revoke(): void
    {
        if (! $this->revokingTokenId) {
            return;
        }

        auth()->user()->tokens()->where('id', $this->revokingTokenId)->delete();

        $this->closeRevokeModal();
        $this->loadTokens();

        Flux::toast(variant: 'success', text: __('Device signed out.'));
    }

    public function closeRevokeModal(): void
    {
        $this->showRevokeModal = false;
        $this->revokingTokenId = null;
        $this->revokingTokenName = '';
    }
}; ?>

<section class="w-full">
    @include('partials.settings-heading')

    <flux:heading level="2" class="sr-only">{{ __('Devices') }}</flux:heading>

    <x-pages::settings.layout :heading="__('Devices')" :subheading="__('Sign out the mobile app on a device you no longer use')">
        <div class="flex flex-col w-full mx-auto space-y-6 text-sm" wire:cloak>
            <div class="border rounded-lg border-line overflow-hidden">
                @forelse ($tokens as $token)
                    <div class="flex items-center justify-between p-4 {{ ! $loop->last ? 'border-b border-line' : '' }}">
                        <div class="flex items-center gap-4">
                            <div class="flex size-10 shrink-0 items-center justify-center rounded-xl bg-surface-raised">
                                <flux:icon.device-phone-mobile class="size-5 text-ink-muted" />
                            </div>
                            <div class="space-y-1">
                                <p class="font-medium tracking-tight text-ink">{{ $token['name'] }}</p>
                                <p class="text-ink-muted text-xs">
                                    {{ __('Added :time', ['time' => $token['created_at_diff']]) }}
                                    @if ($token['last_used_at_diff'])
                                        <span class="opacity-50 mx-1">/</span>
                                        {{ __('Last used :time', ['time' => $token['last_used_at_diff']]) }}
                                    @else
                                        <span class="opacity-50 mx-1">/</span>
                                        {{ __('Never used') }}
                                    @endif
                                </p>
                            </div>
                        </div>

                        <flux:button
                            variant="ghost"
                            size="sm"
                            icon="trash"
                            icon:variant="outline"
                            wire:click="confirmRevoke({{ $token['id'] }})"
                            class="text-red-500 hover:text-red-600 hover:bg-red-50 dark:hover:bg-red-950/50"
                        />
                    </div>
                @empty
                    <div class="p-8 text-center">
                        <div class="mx-auto mb-4 flex size-14 items-center justify-center rounded-2xl bg-surface-raised">
                            <flux:icon.device-phone-mobile class="size-7 text-ink-subtle" />
                        </div>
                        <p class="font-medium text-ink">{{ __('No devices signed in') }}</p>
                        <flux:text class="mt-1">{{ __('Sign in from the mobile app to see it here') }}</flux:text>
                    </div>
                @endforelse
            </div>
        </div>
    </x-pages::settings.layout>

    <flux:modal
        name="revoke-token-modal"
        class="max-w-md md:min-w-md"
        @close="closeRevokeModal"
        wire:model="showRevokeModal"
    >
        <div class="space-y-6">
            <div class="space-y-2">
                <flux:heading size="lg">{{ __('Sign out device') }}</flux:heading>
                <flux:text>
                    {{ __('Are you sure you want to sign out ":name"? It will need to sign in again to use the app.', ['name' => $revokingTokenName]) }}
                </flux:text>
            </div>

            <div class="flex gap-3 justify-end">
                <flux:button
                    variant="outline"
                    wire:click="closeRevokeModal"
                >
                    {{ __('Cancel') }}
                </flux:button>
                <flux:button
                    variant="danger"
                    wire:click="revoke"
                >
                    {{ __('Sign out device') }}
                </flux:button>
            </div>
        </div>
    </flux:modal>
</section>
