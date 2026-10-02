<?php

use App\Providers\AppServiceProvider;
use App\Providers\FortifyServiceProvider;
use App\Providers\PushoverServiceProvider;
use App\Providers\TmdbServiceProvider;

return [
    AppServiceProvider::class,
    FortifyServiceProvider::class,
    TmdbServiceProvider::class,
    PushoverServiceProvider::class,
];
