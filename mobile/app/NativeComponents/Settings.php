<?php

namespace App\NativeComponents;

use App\Exceptions\ApiUnavailable;
use App\Exceptions\Unauthenticated;
use App\Services\Api\Auth;
use App\Services\Api\ScreeningRoomApi;
use Illuminate\View\View;
use Native\Mobile\Edge\Element;
use Native\Mobile\Edge\NativeComponent;
use Native\Mobile\Facades\SecureStorage;

class Settings extends NativeComponent
{
    public string $serverUrl = '';

    public bool $loading = true;

    public string $error = '';

    public ?array $user = null;

    public ?array $stats = null;

    public ?array $integrations = null;

    public bool $showSignOutConfirm = false;

    public function mount(): void
    {
        $this->serverUrl = SecureStorage::get('server_url') ?? '';
        $this->fetchData();
    }

    public function navTitle(): string
    {
        return 'Settings';
    }

    public function fetchData(): void
    {
        $this->loading = true;
        $this->error = '';

        try {
            $api = app(ScreeningRoomApi::class);

            $meResponse = $api->get('me');
            if ($meResponse->successful()) {
                $this->user = $meResponse->json();
            }

            $statsResponse = $api->get('stats');
            if ($statsResponse->successful()) {
                $data = $statsResponse->json();
                $this->stats = $data['headline'] ?? null;
            }

            $integrationsResponse = $api->get('settings/integrations');
            if ($integrationsResponse->successful()) {
                $this->integrations = $integrationsResponse->json();
            }

            if (! $this->user || ! $this->stats || ! $this->integrations) {
                $this->error = 'Failed to load settings';
            }
        } catch (Unauthenticated $e) {
            $this->replace('/sign-in');
        } catch (ApiUnavailable $e) {
            $this->error = $e->getMessage();
        } finally {
            $this->loading = false;
        }
    }

    public function confirmSignOut(): void
    {
        $this->showSignOutConfirm = true;
    }

    public function cancelSignOut(): void
    {
        $this->showSignOutConfirm = false;
    }

    public function signOut(): void
    {
        $auth = app(Auth::class);
        $auth->signOut();
        $this->replace('/sign-in');
    }

    public function render(): Element|View
    {
        return $this->view('settings');
    }
}
