<?php

namespace App\NativeComponents;

use App\Exceptions\ApiUnavailable;
use App\Services\Api\Auth;
use App\Services\Plex\PlexPinAuth;
use Illuminate\View\View;
use Native\Mobile\Attributes\Poll;
use Native\Mobile\Edge\Element;
use Native\Mobile\Edge\NativeComponent;
use Native\Mobile\Facades\Browser;

class SignIn extends NativeComponent
{
    public string $serverUrl = '';

    public string $email = '';

    public string $password = '';

    public bool $loading = false;

    public bool $waitingForPlex = false;

    public bool $showEmailFallback = false;

    public string $error = '';

    public ?int $plexPinId = null;

    public ?string $plexClientId = null;

    public ?int $plexStartedAt = null;

    public function signIn(): void
    {
        $this->loading = true;
        $this->error = '';

        try {
            $auth = app(Auth::class);
            $result = $auth->signIn($this->serverUrl, $this->email, $this->password);

            if ($result['success']) {
                $this->replace('/up-next');
            } else {
                $this->error = $result['error'] ?? 'Sign in failed';
            }
        } catch (ApiUnavailable $e) {
            $this->error = $e->getMessage();
        } finally {
            $this->loading = false;
        }
    }

    public function signInWithPlex(): void
    {
        $this->error = '';

        if (trim($this->serverUrl) === '') {
            $this->error = 'Please enter a server URL';

            return;
        }

        try {
            $server = app(Auth::class)->checkServer($this->serverUrl);

            if (! $server['success']) {
                $this->error = $server['error'] ?? 'Invalid server URL';

                return;
            }

            $plexAuth = app(PlexPinAuth::class);
            $pin = $plexAuth->start();

            $this->plexPinId = $pin['id'];
            $this->plexClientId = $pin['client_id'];
            $this->plexStartedAt = time();
            $this->waitingForPlex = true;

            // The in-app Safari sheet: Browser::auth() needs a deep-link scheme to report back, and
            // without one it opens nothing. The user taps Done after logging in; polling does the rest.
            if (! Browser::inApp($pin['url'])) {
                Browser::open($pin['url']);
            }
        } catch (ApiUnavailable $e) {
            $this->error = $e->getMessage();
        }
    }

    public function cancelPlexSignIn(): void
    {
        $this->stopWaitingForPlex();
        $this->error = '';
    }

    public function toggleEmailFallback(): void
    {
        $this->showEmailFallback = ! $this->showEmailFallback;
    }

    #[Poll(2000)]
    public function pollPlexAuth(): void
    {
        if (! $this->waitingForPlex || $this->plexPinId === null) {
            return;
        }

        if ($this->plexStartedAt && time() - $this->plexStartedAt > 300) {
            $this->error = 'Plex sign-in timed out. Please try again.';
            $this->stopWaitingForPlex();

            return;
        }

        try {
            $plexToken = app(PlexPinAuth::class)->check($this->plexPinId, $this->plexClientId);

            if ($plexToken === null) {
                return; // Still waiting
            }

            $result = app(Auth::class)->signInWithPlex($this->serverUrl, $plexToken);

            if ($result['success']) {
                $this->waitingForPlex = false;
                $this->replace('/up-next');
            } else {
                $this->error = $result['error'] ?? 'Sign in failed';
                $this->stopWaitingForPlex();
            }
        } catch (ApiUnavailable $e) {
            $this->error = $e->getMessage();
            $this->stopWaitingForPlex();
        }
    }

    private function stopWaitingForPlex(): void
    {
        $this->waitingForPlex = false;
        $this->plexPinId = null;
        $this->plexClientId = null;
        $this->plexStartedAt = null;
    }

    public function render(): Element|View
    {
        return $this->view('sign-in');
    }
}
