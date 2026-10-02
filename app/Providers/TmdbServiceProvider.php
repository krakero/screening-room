<?php

namespace App\Providers;

use App\Services\Tmdb\TmdbClient;
use Illuminate\Support\ServiceProvider;

class TmdbServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(TmdbClient::class);
    }
}
