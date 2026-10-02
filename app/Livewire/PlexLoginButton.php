<?php

namespace App\Livewire;

use App\Models\User;
use App\Services\Plex\PlexDiscovery;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Laravel\Fortify\Events\TwoFactorAuthenticationChallenged;
use Livewire\Component;
use Throwable;

/**
 * "Sign in with Plex" on the login screen — an island embedded in the plain-Blade Fortify login
 * view. Only ever logs in an EXISTING user whose `plex_account_id` matches the signed-in Plex
 * account's stable uuid; never creates or links an account (that only happens in setup or
 * Settings > Security). Respects the app's own 2FA and mirrors Fortify's login throttling.
 */
class PlexLoginButton extends Component
{
    private const SIGN_IN_MAX_ATTEMPTS = 40;

    private const THROTTLE_MAX_ATTEMPTS = 5;

    private const THROTTLE_DECAY_SECONDS = 60;

    public bool $available = false;

    public bool $remember = false;

    public ?int $pinId = null;

    public string $authUrl = '';

    public bool $polling = false;

    public int $attempts = 0;

    public function mount(): void
    {
        $this->available = User::query()->whereNotNull('plex_account_id')->exists();
    }

    public function startSignIn(PlexDiscovery $discovery): void
    {
        if (! $this->available) {
            return;
        }

        try {
            $pin = $discovery->createPin();
        } catch (Throwable) {
            $this->addError('plex', __('Could not start Plex sign-in. Try again in a moment.'));

            return;
        }

        $this->pinId = $pin['id'];
        $this->authUrl = $pin['auth_url'];
        $this->attempts = 0;
        $this->polling = true;

        $this->dispatch('plex-auth-opened', url: $pin['auth_url']);
    }

    public function poll(PlexDiscovery $discovery): void
    {
        if (! $this->polling || $this->pinId === null) {
            return;
        }

        $this->attempts++;

        try {
            $token = $discovery->checkPin($this->pinId);
        } catch (Throwable) {
            $token = null;
        }

        if ($token === null) {
            if ($this->attempts >= self::SIGN_IN_MAX_ATTEMPTS) {
                $this->polling = false;
                $this->addError('plex', __('Plex sign-in timed out. Try again.'));
            }

            return;
        }

        $this->polling = false;
        $this->resolve($discovery, $token);
    }

    private function resolve(PlexDiscovery $discovery, string $token): void
    {
        if ($this->tooManyAttempts()) {
            $this->addError('plex', __('Too many attempts. Please try again later.'));

            return;
        }

        $account = $discovery->account($token);
        $uuid = $account['uuid'] ?? null;

        $user = filled($uuid) ? User::query()->where('plex_account_id', $uuid)->first() : null;

        if ($user === null) {
            RateLimiter::hit($this->throttleKey(), self::THROTTLE_DECAY_SECONDS);
            $this->addError('plex', __('This Plex account isn\'t linked to Screening Room.'));

            return;
        }

        RateLimiter::clear($this->throttleKey());

        if ($user->two_factor_secret !== null && $user->two_factor_confirmed_at !== null) {
            session()->put([
                'login.id' => $user->getKey(),
                'login.remember' => $this->remember,
            ]);

            TwoFactorAuthenticationChallenged::dispatch($user);

            $this->redirectRoute('two-factor.login', navigate: true);

            return;
        }

        Auth::login($user, $this->remember);
        session()->regenerate();

        $this->redirectIntended(default: route('dashboard', absolute: false), navigate: true);
    }

    private function tooManyAttempts(): bool
    {
        return RateLimiter::tooManyAttempts($this->throttleKey(), self::THROTTLE_MAX_ATTEMPTS);
    }

    private function throttleKey(): string
    {
        return Str::transliterate('plex-login|'.request()->ip());
    }

    public function render(): View
    {
        return view('livewire.plex-login-button');
    }
}
