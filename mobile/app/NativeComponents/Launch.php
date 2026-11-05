<?php

namespace App\NativeComponents;

use App\Services\Api\Auth;
use Illuminate\View\View;
use Native\Mobile\Edge\Element;
use Native\Mobile\Edge\NativeComponent;
use Native\Mobile\Facades\SecureStorage;

class Launch extends NativeComponent
{
    public function mount(): void
    {
        $auth = app(Auth::class);

        $dev = config('services.dev_sign_in');
        if (app()->isLocal() && ! $auth->isSignedIn() && filled($dev['server_url'] ?? null) && filled($dev['token'] ?? null)) {
            SecureStorage::set('server_url', $dev['server_url']);
            SecureStorage::set('api_token', $dev['token']);
        }

        if ($auth->isSignedIn()) {
            $this->replace(app()->isLocal() && filled($dev['start_route'] ?? null) ? $dev['start_route'] : '/up-next');
        } else {
            $this->replace('/sign-in');
        }
    }

    public function render(): Element|View
    {
        return $this->view('launch');
    }
}
