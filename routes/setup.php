<?php

use Illuminate\Support\Facades\Route;

Route::livewire('setup/account', 'pages::setup.account')->name('setup.account');
Route::livewire('setup/restore', 'pages::setup.restore')->name('setup.restore');

Route::middleware(['auth'])->group(function () {
    Route::livewire('setup/tmdb', 'pages::setup.tmdb')->name('setup.tmdb');
    Route::livewire('setup/preferences', 'pages::setup.preferences')->name('setup.preferences');
    Route::livewire('setup/import', 'pages::setup.import')->name('setup.import');
    Route::livewire('setup/plex', 'pages::setup.plex')->name('setup.plex');
    Route::livewire('setup/requests', 'pages::setup.requests')->name('setup.requests');
    Route::livewire('setup/notifications', 'pages::setup.notifications')->name('setup.notifications');
    Route::livewire('setup/done', 'pages::setup.done')->name('setup.done');
});
