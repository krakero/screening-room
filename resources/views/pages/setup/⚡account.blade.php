<?php

use App\Actions\Fortify\CreateNewUser;
use App\Models\User;
use App\Services\Plex\PlexDiscovery;
use App\Support\IntegrationSettings;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts::setup')] #[Title('Set up Screening Room')] class extends Component
{
    public string $name = '';

    public string $email = '';

    public string $password = '';

    public string $password_confirmation = '';

    public ?int $plexSignInPinId = null;

    public string $plexSignInAuthUrl = '';

    public bool $plexSignInPolling = false;

    public int $plexSignInAttempts = 0;

    private const PLEX_SIGN_IN_MAX_ATTEMPTS = 40;

    public function mount(): void
    {
        if (User::query()->exists()) {
            $this->redirectRoute('setup.tmdb', navigate: true);
        }
    }

    public function createAccount(CreateNewUser $creator): void
    {
        if (User::query()->exists()) {
            $this->redirectRoute('setup.tmdb', navigate: true);

            return;
        }

        $user = $creator->create([
            'name' => $this->name,
            'email' => $this->email,
            'password' => $this->password,
            'password_confirmation' => $this->password_confirmation,
        ]);

        $user->forceFill(['email_verified_at' => now()])->save();

        Auth::login($user);

        $this->redirectRoute('setup.tmdb', navigate: true);
    }

    public function startPlexSignIn(PlexDiscovery $discovery): void
    {
        if (User::query()->exists()) {
            $this->redirectRoute('setup.tmdb', navigate: true);

            return;
        }

        try {
            $pin = $discovery->createPin();
        } catch (\Throwable) {
            $this->addError('plex', __('Could not start Plex sign-in. Try again in a moment.'));

            return;
        }

        $this->plexSignInPinId = $pin['id'];
        $this->plexSignInAuthUrl = $pin['auth_url'];
        $this->plexSignInAttempts = 0;
        $this->plexSignInPolling = true;

        $this->dispatch('plex-auth-opened', url: $pin['auth_url']);
    }

    public function pollPlexSignIn(PlexDiscovery $discovery, IntegrationSettings $settings): void
    {
        if (! $this->plexSignInPolling || $this->plexSignInPinId === null) {
            return;
        }

        $this->plexSignInAttempts++;

        try {
            $token = $discovery->checkPin($this->plexSignInPinId);
        } catch (\Throwable) {
            $token = null;
        }

        if ($token === null) {
            if ($this->plexSignInAttempts >= self::PLEX_SIGN_IN_MAX_ATTEMPTS) {
                $this->plexSignInPolling = false;
                $this->addError('plex', __('Plex sign-in timed out. Try again.'));
            }

            return;
        }

        $this->plexSignInPolling = false;

        if (User::query()->exists()) {
            $this->redirectRoute('setup.tmdb', navigate: true);

            return;
        }

        $account = $discovery->account($token);

        if ($account === null || blank($account['uuid'])) {
            $this->addError('plex', __('Could not read your Plex account. Try again.'));

            return;
        }

        $displayName = filled($account['username']) ? $account['username'] : __('Plex user');
        $email = filled($account['email']) ? $account['email'] : Str::slug($displayName).'@plex.local';

        $user = User::create([
            'name' => $displayName,
            'email' => $email,
            'password' => Str::password(40),
        ]);

        $user->forceFill([
            'email_verified_at' => now(),
            'plex_account_id' => $account['uuid'],
            'plex_username' => $account['username'] ?: null,
            'plex_linked_at' => now(),
            'password_set' => false,
        ])->save();

        // Pre-fill Plex server discovery (PLEX-D1) with the same token, so the Plex setup step is one click.
        $settings->setMany([
            'plex.token' => $token,
            'plex.account_name' => $displayName,
            'plex.account_uuid' => $account['uuid'],
            'plex.account_plex_id' => $account['id'],
        ]);

        try {
            $settings->set('plex.servers', $discovery->servers($token));
        } catch (\Throwable) {
            // Best-effort — the Plex setup step can still discover servers itself.
        }

        Auth::login($user);

        $this->redirectRoute('setup.tmdb', navigate: true);
    }
}; ?>

<x-setup.layout step="account" :title="__('Welcome to Screening Room')" :subtitle="__('Let\'s get your server set up. First, create your account — it\'s the only one this app will ever have.')">
    @php
        $expandEmailOptions = $errors->any();
    @endphp

    <div
        x-data
        x-on:plex-auth-opened.window="window.open($event.detail.url, '_blank')"
        {{-- Poll only while waiting on Plex: every re-render morphs the <details> below back to
            its server-rendered (closed) state, which would snap "Other options" shut mid-typing. --}}
        @if ($plexSignInPolling) wire:poll.2s="pollPlexSignIn" @endif
        class="mb-6"
    >
        <flux:text size="sm" variant="subtle" class="mb-3">{{ __('Creates your account from your Plex login — no password to remember, and Plex server discovery is pre-filled for the next step.') }}</flux:text>

        <button
            type="button"
            wire:click="startPlexSignIn"
            wire:loading.attr="disabled"
            wire:target="startPlexSignIn"
            :disabled="$plexSignInPolling"
            data-test="setup-plex-button"
            class="inline-flex h-12 w-full shrink-0 items-center justify-center gap-2 rounded-lg bg-plex px-4 text-base font-semibold text-plex-foreground shadow-xs transition hover:bg-[color-mix(in_oklab,var(--color-plex),black_10%)] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-plex focus-visible:ring-offset-2 focus-visible:ring-offset-canvas disabled:cursor-not-allowed disabled:opacity-60"
        >
            @if ($plexSignInPolling)
                <flux:icon.loading variant="micro" />
                {{ __('Waiting for sign-in…') }}
            @else
                <svg viewBox="0 0 24 24" class="size-5 shrink-0" fill="none" aria-hidden="true">
                    <path d="M12 3a9 9 0 1 0 0 18 9 9 0 0 0 0-18Zm-1.5 5 5 4-5 4V8Z" fill="currentColor" />
                </svg>
                {{ __('Sign in with Plex') }}
            @endif
        </button>

        @error('plex')
            <flux:text size="sm" class="mt-3 text-red-500">{{ $message }}</flux:text>
        @enderror

        @if ($plexSignInPolling && $plexSignInAuthUrl !== '')
            <flux:text size="sm" variant="subtle" class="mt-3">
                {{ __('A tab should have opened at app.plex.tv. If it didn\'t,') }}
                <flux:link href="{{ $plexSignInAuthUrl }}" target="_blank" rel="noopener">{{ __('open it manually') }}</flux:link>.
            </flux:text>
        @endif
    </div>

    <details class="group"{{ $expandEmailOptions ? ' open' : '' }}>
        <summary class="flex cursor-pointer list-none items-center justify-center gap-2 text-sm font-medium text-ink-muted transition hover:text-ink">
            <flux:icon.chevron-right class="size-4 transition group-open:rotate-90" />
            {{ __('Other options') }}
        </summary>

        <form wire:submit="createAccount" class="mt-6 space-y-6">
            <flux:text size="sm" variant="subtle">{{ __('Create an account with email instead') }}</flux:text>

            <flux:input wire:model="name" :label="__('Name')" required />
            <flux:input wire:model="email" type="email" :label="__('Email')" required />
            <flux:input wire:model="password" type="password" viewable :label="__('Password')" required />
            <flux:input wire:model="password_confirmation" type="password" viewable :label="__('Confirm password')" required />

            <flux:button type="submit" variant="primary" class="w-full">{{ __('Create account & continue') }}</flux:button>
        </form>
    </details>

    <div class="mt-6 text-center">
        <flux:link :href="route('setup.restore')" wire:navigate class="text-sm text-ink-subtle">{{ __('Restoring from a backup?') }}</flux:link>
    </div>
</x-setup.layout>
