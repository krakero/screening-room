<?php

namespace App\Providers;

use App\Notifications\Channels\PushoverChannel;
use App\Services\Pushover\PushoverClient;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\ServiceProvider;

class PushoverServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(PushoverClient::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Notification::extend('pushover', fn ($app): PushoverChannel => $app->make(PushoverChannel::class));
    }
}
