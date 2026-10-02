<?php

use App\Http\Controllers\Webhooks\ArrWebhookController;
use App\Http\Controllers\Webhooks\PlexWebhookController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/dashboard')->name('home');

Route::post('webhooks/arr/{secret}', ArrWebhookController::class)->name('webhooks.arr');
Route::post('webhooks/plex/{secret}', PlexWebhookController::class)->name('webhooks.plex');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::livewire('dashboard', 'pages::dashboard')->name('dashboard');
    Route::livewire('discover', 'pages::discover')->name('discover');
    Route::livewire('downloads', 'pages::downloads')->name('downloads');
    Route::livewire('search', 'pages::search')->name('search');
    Route::livewire('calendar', 'pages::calendar')->name('calendar');
    Route::livewire('history', 'pages::history')->name('history');
    Route::livewire('stats', 'pages::stats')->name('stats');
    Route::livewire('lists', 'pages::lists.index')->name('lists.index');
    Route::livewire('lists/{mediaList:slug}', 'pages::lists.show')->name('lists.show');
    Route::livewire('collection', 'pages::collection')->middleware('collection.enabled')->name('collection.index');
    Route::livewire('titles/tmdb/{type}/{tmdbId}', 'pages::titles.importing')
        ->where('type', 'movie|show')
        ->where('tmdbId', '[0-9]+')
        ->name('titles.tmdb');
    Route::livewire('titles/{title}/seasons/{seasonNumber}', 'pages::titles.seasons.show')
        ->where('seasonNumber', '[0-9]+')
        ->name('titles.seasons.show');
    Route::livewire('titles/{title}', 'pages::titles.show')->name('titles.show');

    if (! app()->isProduction()) {
        Route::livewire('styleguide', 'pages::styleguide')->name('styleguide');
    }
});

require __DIR__.'/setup.php';
require __DIR__.'/settings.php';
