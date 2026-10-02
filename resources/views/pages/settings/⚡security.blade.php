<?php

use App\Concerns\PasswordValidationRules;
use App\Models\User;
use App\Services\Plex\PlexDiscovery;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Actions\DisableTwoFactorAuthentication;
use Laravel\Fortify\Features;
use Laravel\Fortify\Fortify;
use Laravel\Passkeys\Actions\DeletePasskey;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Security settings')] class extends Component
{
    use PasswordValidationRules;

    public string $current_password = '';

    public string $password = '';

    public string $password_confirmation = '';

    public bool $canManageTwoFactor;

    public bool $twoFactorEnabled;

    public bool $requiresConfirmation;

    #[Locked]
    public bool $canManagePasskeys;

    #[Locked]
    public array $passkeys = [];

    public bool $showDeleteModal = false;

    #[Locked]
    public ?int $deletingPasskeyId = null;

    #[Locked]
    public string $deletingPasskeyName = '';

    public bool $plexLinked = false;

    public string $plexUsername = '';

    public bool $passwordSet = true;

    public string $newPassword = '';

    public string $newPassword_confirmation = '';

    public ?int $plexSignInPinId = null;

    public string $plexSignInAuthUrl = '';

    public bool $plexSignInPolling = false;

    public int $plexSignInAttempts = 0;

    private const PLEX_SIGN_IN_MAX_ATTEMPTS = 40;

    /**
     * Mount the component.
     */
    public function mount(DisableTwoFactorAuthentication $disableTwoFactorAuthentication): void
    {
        $this->canManageTwoFactor = Features::canManageTwoFactorAuthentication();

        if ($this->canManageTwoFactor) {
            if (Fortify::confirmsTwoFactorAuthentication() && is_null(auth()->user()->two_factor_confirmed_at)) {
                $disableTwoFactorAuthentication(auth()->user());
            }

            $this->twoFactorEnabled = auth()->user()->hasEnabledTwoFactorAuthentication();
            $this->requiresConfirmation = Features::optionEnabled(Features::twoFactorAuthentication(), 'confirm');
        }

        $this->canManagePasskeys = Features::canManagePasskeys();

        if ($this->canManagePasskeys) {
            $this->loadPasskeys();
        }

        $this->plexLinked = auth()->user()->hasLinkedPlexAccount();
        $this->plexUsername = (string) auth()->user()->plex_username;
        $this->passwordSet = (bool) auth()->user()->password_set;
    }

    /**
     * Set the account's first real password (accounts created via "Sign in with Plex" get a
     * random, unknown one). Skips the usual `current_password` check since the user has no way
     * to know it.
     */
    public function setInitialPassword(): void
    {
        if ($this->passwordSet) {
            return;
        }

        $this->validate([
            'newPassword' => $this->passwordRules(),
        ]);

        Auth::user()->forceFill([
            'password' => $this->newPassword,
            'password_set' => true,
        ])->save();

        $this->passwordSet = true;
        $this->reset('newPassword', 'newPassword_confirmation');

        Flux::toast(variant: 'success', text: __('Password set — you can now log in with your email and this password too.'));
    }

    public function startPlexLink(PlexDiscovery $discovery): void
    {
        try {
            $pin = $discovery->createPin();
        } catch (Throwable) {
            Flux::toast(variant: 'danger', text: __('Could not start Plex sign-in. Try again in a moment.'));

            return;
        }

        $this->plexSignInPinId = $pin['id'];
        $this->plexSignInAuthUrl = $pin['auth_url'];
        $this->plexSignInAttempts = 0;
        $this->plexSignInPolling = true;

        $this->dispatch('plex-auth-opened', url: $pin['auth_url']);
    }

    public function pollPlexLink(PlexDiscovery $discovery): void
    {
        if (! $this->plexSignInPolling || $this->plexSignInPinId === null) {
            return;
        }

        $this->plexSignInAttempts++;

        try {
            $token = $discovery->checkPin($this->plexSignInPinId);
        } catch (Throwable) {
            $token = null;
        }

        if ($token === null) {
            if ($this->plexSignInAttempts >= self::PLEX_SIGN_IN_MAX_ATTEMPTS) {
                $this->plexSignInPolling = false;
                Flux::toast(variant: 'danger', text: __('Plex sign-in timed out. Try again.'));
            }

            return;
        }

        $this->plexSignInPolling = false;

        $account = $discovery->account($token);

        if ($account === null || blank($account['uuid'])) {
            Flux::toast(variant: 'danger', text: __('Could not read your Plex account. Try again.'));

            return;
        }

        $existing = User::query()->where('plex_account_id', $account['uuid'])->first();

        if ($existing !== null && $existing->isNot(Auth::user())) {
            Flux::toast(variant: 'danger', text: __('That Plex account is already linked to a different user.'));

            return;
        }

        Auth::user()->forceFill([
            'plex_account_id' => $account['uuid'],
            'plex_username' => $account['username'] ?: null,
            'plex_linked_at' => now(),
        ])->save();

        $this->plexLinked = true;
        $this->plexUsername = (string) $account['username'];

        Flux::toast(variant: 'success', text: __('Plex account linked.'));
    }

    public function unlinkPlex(): void
    {
        if (! $this->passwordSet) {
            Flux::toast(variant: 'danger', text: __('Set a password first, or you\'ll lock yourself out.'));

            return;
        }

        Auth::user()->forceFill([
            'plex_account_id' => null,
            'plex_username' => null,
            'plex_linked_at' => null,
        ])->save();

        $this->plexLinked = false;
        $this->plexUsername = '';

        Flux::toast(variant: 'success', text: __('Plex account unlinked.'));
    }

    /**
     * Update the password for the currently authenticated user.
     */
    public function updatePassword(): void
    {
        try {
            $validated = $this->validate([
                'current_password' => $this->currentPasswordRules(),
                'password' => $this->passwordRules(),
            ]);
        } catch (ValidationException $e) {
            $this->reset('current_password', 'password', 'password_confirmation');

            throw $e;
        }

        Auth::user()->update([
            'password' => $validated['password'],
        ]);

        $this->reset('current_password', 'password', 'password_confirmation');

        Flux::toast(variant: 'success', text: __('Password updated.'));
    }

    /**
     * Load the user's passkeys.
     */
    public function loadPasskeys(): void
    {
        $this->passkeys = auth()->user()->passkeys()
            ->select(['id', 'name', 'credential', 'created_at', 'last_used_at'])
            ->latest()
            ->get()
            ->map(fn ($passkey) => [
                'id' => $passkey->id,
                'name' => $passkey->name,
                'authenticator' => $passkey->authenticator,
                'created_at_diff' => $passkey->created_at->diffForHumans(),
                'last_used_at_diff' => $passkey->last_used_at?->diffForHumans(),
            ])
            ->toArray();
    }

    /**
     * Show the delete confirmation modal.
     */
    public function confirmDelete(int $passkeyId): void
    {
        $passkey = auth()->user()->passkeys()->findOrFail($passkeyId);

        $this->deletingPasskeyId = $passkey->id;
        $this->deletingPasskeyName = $passkey->name;
        $this->showDeleteModal = true;
    }

    /**
     * Delete the passkey.
     */
    public function deletePasskey(DeletePasskey $deletePasskey): void
    {
        if (! $this->deletingPasskeyId) {
            return;
        }

        $passkey = auth()->user()->passkeys()->findOrFail($this->deletingPasskeyId);

        $deletePasskey(auth()->user(), $passkey);

        $this->closeDeleteModal();
        $this->loadPasskeys();
    }

    /**
     * Close the delete confirmation modal.
     */
    public function closeDeleteModal(): void
    {
        $this->showDeleteModal = false;
        $this->deletingPasskeyId = null;
        $this->deletingPasskeyName = '';
    }

    /**
     * Handle the two-factor authentication enabled event.
     */
    #[On('two-factor-enabled')]
    public function onTwoFactorEnabled(): void
    {
        $this->twoFactorEnabled = true;
    }

    /**
     * Disable two-factor authentication for the user.
     */
    public function disable(DisableTwoFactorAuthentication $disableTwoFactorAuthentication): void
    {
        $disableTwoFactorAuthentication(auth()->user());

        $this->twoFactorEnabled = false;
    }
}; ?>

<section class="w-full">
    @include('partials.settings-heading')

    <flux:heading level="2" class="sr-only">{{ __('Security settings') }}</flux:heading>

    <x-pages::settings.layout :heading="__('Update password')" :subheading="__('Ensure your account is using a long, random password to stay secure')">
        @if ($passwordSet)
            <form method="POST" wire:submit="updatePassword" class="mt-6 space-y-6">
                <flux:input
                    wire:model="current_password"
                    :label="__('Current password')"
                    type="password"
                    required
                    autocomplete="current-password"
                    viewable
                />
                <flux:input
                    wire:model="password"
                    :label="__('New password')"
                    type="password"
                    required
                    autocomplete="new-password"
                    passwordrules="{{ \Illuminate\Validation\Rules\Password::defaults()->toPasswordRulesString() }}"
                    viewable
                />
                <flux:input
                    wire:model="password_confirmation"
                    :label="__('Confirm password')"
                    type="password"
                    required
                    autocomplete="new-password"
                    passwordrules="{{ \Illuminate\Validation\Rules\Password::defaults()->toPasswordRulesString() }}"
                    viewable
                />

                <div class="flex items-center gap-4">
                    <flux:button variant="primary" type="submit" data-test="update-password-button">
                        {{ __('Save') }}
                    </flux:button>
                </div>
            </form>
        @else
            <div class="mt-6 space-y-6">
                <flux:callout icon="information-circle">
                    <flux:callout.text>{{ __('This account was created with "Sign in with Plex" and doesn\'t have a password yet. Set one so you can also log in with your email, and so you can unlink Plex later if you want.') }}</flux:callout.text>
                </flux:callout>

                <form wire:submit="setInitialPassword" class="space-y-6">
                    <flux:input
                        wire:model="newPassword"
                        :label="__('New password')"
                        type="password"
                        required
                        autocomplete="new-password"
                        passwordrules="{{ \Illuminate\Validation\Rules\Password::defaults()->toPasswordRulesString() }}"
                        viewable
                    />
                    <flux:input
                        wire:model="newPassword_confirmation"
                        :label="__('Confirm password')"
                        type="password"
                        required
                        autocomplete="new-password"
                        viewable
                    />

                    <flux:button variant="primary" type="submit" data-test="set-initial-password-button">
                        {{ __('Set password') }}
                    </flux:button>
                </form>
            </div>
        @endif

        <section class="mt-12">
            <flux:heading>{{ __('Plex account') }}</flux:heading>
            <flux:subheading>{{ __('Link your Plex account to sign in without a password') }}</flux:subheading>

            <div class="mt-6 flex flex-col w-full mx-auto space-y-4 text-sm" wire:cloak>
                @if ($plexLinked)
                    <flux:card variant="outline" :highlight="false" class="border-line bg-surface">
                        <flux:text>{{ __('Connected as :name.', ['name' => $plexUsername]) }}</flux:text>
                        <flux:button size="sm" variant="danger" wire:click="unlinkPlex" wire:loading.attr="disabled">{{ __('Unlink') }}</flux:button>
                    </flux:card>
                    @if (! $passwordSet)
                        <flux:text size="sm" variant="subtle">{{ __('Set a password above before unlinking, or you won\'t be able to log back in.') }}</flux:text>
                    @endif
                @else
                    <flux:card variant="outline" :highlight="false" class="border-line bg-surface" x-data x-on:plex-auth-opened.window="window.open($event.detail.url, '_blank')" wire:poll.2s="pollPlexLink">
                        <div class="flex items-center justify-between gap-4">
                            <flux:text variant="subtle">{{ __('Not linked — you can only sign in with your email and password.') }}</flux:text>
                            <flux:button size="sm" wire:click="startPlexLink" wire:loading.attr="disabled" wire:target="startPlexLink" :disabled="$plexSignInPolling">
                                @if ($plexSignInPolling)
                                    <span class="inline-flex items-center gap-2"><flux:icon.loading variant="micro" /> {{ __('Waiting…') }}</span>
                                @else
                                    {{ __('Link Plex account') }}
                                @endif
                            </flux:button>
                        </div>

                        @if ($plexSignInPolling && $plexSignInAuthUrl !== '')
                            <flux:text size="sm" variant="subtle" class="mt-3">
                                {{ __('A tab should have opened at app.plex.tv. If it didn\'t,') }}
                                <flux:link href="{{ $plexSignInAuthUrl }}" target="_blank" rel="noopener">{{ __('open it manually') }}</flux:link>.
                            </flux:text>
                        @endif
                    </flux:card>
                @endif
            </div>
        </section>

        @if ($canManageTwoFactor)
            <section class="mt-12">
                <flux:heading>{{ __('Two-factor authentication') }}</flux:heading>
                <flux:subheading>{{ __('Manage your two-factor authentication settings') }}</flux:subheading>

                <div class="flex flex-col w-full mx-auto space-y-6 text-sm" wire:cloak>
                    @if ($twoFactorEnabled)
                        <div class="space-y-4">
                            <flux:text>
                                {{ __('You will be prompted for a secure, random pin during login, which you can retrieve from the TOTP-supported application on your phone.') }}
                            </flux:text>

                            <div class="flex justify-start">
                                <flux:button
                                    variant="danger"
                                    wire:click="disable"
                                >
                                    {{ __('Disable 2FA') }}
                                </flux:button>
                            </div>

                            <livewire:pages::settings.two-factor.recovery-codes :$requiresConfirmation />
                        </div>
                    @else
                        <div class="space-y-4">
                            <flux:text variant="subtle">
                                {{ __('When you enable two-factor authentication, you will be prompted for a secure pin during login. This pin can be retrieved from a TOTP-supported application on your phone.') }}
                            </flux:text>

                            <flux:modal.trigger name="two-factor-setup-modal">
                                <flux:button
                                    variant="primary"
                                    wire:click="$dispatch('start-two-factor-setup')"
                                >
                                    {{ __('Enable 2FA') }}
                                </flux:button>
                            </flux:modal.trigger>

                            <livewire:pages::settings.two-factor-setup-modal :requires-confirmation="$requiresConfirmation" />
                        </div>
                    @endif
                </div>
            </section>
        @endif

        @if ($canManagePasskeys)
            <section class="mt-12">
                <flux:heading>{{ __('Passkeys') }}</flux:heading>
                <flux:subheading>{{ __('Manage your passkeys for passwordless sign-in') }}</flux:subheading>

                <div class="mt-6 flex flex-col w-full mx-auto space-y-6 text-sm" wire:cloak>
                    <div class="border rounded-lg border-line overflow-hidden">
                        @forelse ($passkeys as $passkey)
                            <div class="flex items-center justify-between p-4 {{ ! $loop->last ? 'border-b border-line' : '' }}">
                                <div class="flex items-center gap-4">
                                    <div class="flex size-10 shrink-0 items-center justify-center rounded-xl bg-surface-raised">
                                        <flux:icon.key class="size-5 text-ink-muted" />
                                    </div>
                                    <div class="space-y-1">
                                        <div class="flex items-center gap-2.5">
                                            <p class="font-medium tracking-tight text-ink">{{ $passkey['name'] }}</p>
                                            @if ($passkey['authenticator'])
                                                <x-media.badge>{{ $passkey['authenticator'] }}</x-media.badge>
                                            @endif
                                        </div>
                                        <p class="text-ink-muted text-xs">
                                            {{ __('Added :time', ['time' => $passkey['created_at_diff']]) }}
                                            @if ($passkey['last_used_at_diff'])
                                                <span class="opacity-50 mx-1">/</span>
                                                {{ __('Last used :time', ['time' => $passkey['last_used_at_diff']]) }}
                                            @endif
                                        </p>
                                    </div>
                                </div>

                                <flux:button
                                    variant="ghost"
                                    size="sm"
                                    icon="trash"
                                    icon:variant="outline"
                                    wire:click="confirmDelete({{ $passkey['id'] }})"
                                    class="text-red-500 hover:text-red-600 hover:bg-red-50 dark:hover:bg-red-950/50"
                                />
                            </div>
                        @empty
                            <div class="p-8 text-center">
                                <div class="mx-auto mb-4 flex size-14 items-center justify-center rounded-2xl bg-surface-raised">
                                    <flux:icon.key class="size-7 text-ink-subtle" />
                                </div>
                                <p class="font-medium text-ink">{{ __('No passkeys yet') }}</p>
                                <flux:text class="mt-1">{{ __('Add a passkey to sign in without a password') }}</flux:text>
                            </div>
                        @endforelse
                    </div>

                    <x-passkey-registration />
                </div>
            </section>
        @endif
    </x-pages::settings.layout>

    <flux:modal
        name="delete-passkey-modal"
        class="max-w-md md:min-w-md"
        @close="closeDeleteModal"
        wire:model="showDeleteModal"
    >
        <div class="space-y-6">
            <div class="space-y-2">
                <flux:heading size="lg">{{ __('Remove passkey') }}</flux:heading>
                <flux:text>
                    {{ __('Are you sure you want to remove the passkey ":name"? You will no longer be able to use it to sign in.', ['name' => $deletingPasskeyName]) }}
                </flux:text>
            </div>

            <div class="flex gap-3 justify-end">
                <flux:button
                    variant="outline"
                    wire:click="closeDeleteModal"
                >
                    {{ __('Cancel') }}
                </flux:button>
                <flux:button
                    variant="danger"
                    wire:click="deletePasskey"
                >
                    {{ __('Remove passkey') }}
                </flux:button>
            </div>
        </div>
    </flux:modal>
</section>
