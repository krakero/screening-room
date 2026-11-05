<?php

use App\NativeComponents\Calendar;
use App\NativeComponents\Launch;
use App\NativeComponents\Layouts\StackLayout;
use App\NativeComponents\Layouts\TabsLayout;
use App\NativeComponents\Search;
use App\NativeComponents\Settings;
use App\NativeComponents\SignIn;
use App\NativeComponents\TitleDetail;
use App\NativeComponents\UpNext;
use Illuminate\Support\Facades\Route;

// Launch screen (no layout)
Route::native('/', Launch::class);

// Sign-in screen (no layout)
Route::native('/sign-in', SignIn::class);

// Tabbed screens
Route::nativeGroup(TabsLayout::class, function () {
    Route::native('/up-next', UpNext::class);
    Route::native('/calendar', Calendar::class);
    Route::native('/search', Search::class);
    Route::native('/settings', Settings::class);
});

// Detail screen with stack layout (back button, no tabs)
Route::native('/titles/{id}', TitleDetail::class)
    ->layout(StackLayout::class);
